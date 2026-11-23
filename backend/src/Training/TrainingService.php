<?php
namespace App\Training;

use App\Entity\{Diver, Enrolment, Evaluation, Section, TeachingSession, User};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, BadRequestHttpException};

/** Suivi des stagiaires : règles de validation du MFT, état des compétences et délivrance. */
final class TrainingService
{
    public function __construct(private EntityManagerInterface $em) {}

    public static function curriculumOf(Enrolment $e): array
    {
        return Mft::curriculum($e->getCurriculum()) ?? throw new \LogicException('Cursus inconnu : ' . $e->getCurriculum());
    }

    /** Sections où le stagiaire est inscrit. @return Section[] */
    public function sectionsOf(Diver $d): array
    {
        return array_values(array_filter($this->em->getRepository(Section::class)->findBy([], ['name' => 'ASC']), fn (Section $s) => $s->getDivers()->contains($d)));
    }

    /** Évaluer un stagiaire : référents, ou encadrants d'une de ses sections. */
    public function canEvaluate(User $u, Diver $d): bool
    {
        if ($u->isReferent()) return true;
        foreach ($this->sectionsOf($d) as $s) if ($s->getInstructors()->contains($u)) return true;
        return false;
    }

    public function assertCanEvaluate(User $u, Diver $d): void
    {
        if (!$this->canEvaluate($u, $d)) throw new AccessDeniedHttpException('Réservé aux encadrants des sections du stagiaire et aux référents');
    }

    /** Dernière évaluation de chaque élément. @return array<string, Evaluation> */
    public function current(Enrolment $e): array
    {
        $last = [];
        foreach ($e->getEvaluations() as $ev) $last[$ev->getSkill()] = $ev; // triées par date puis id
        return $last;
    }

    /** Statut de chaque élément : NT, ECA, A ou V. @return array<string, string> */
    public function skillStatuses(Enrolment $e, ?array $current = null): array
    {
        $current ??= $this->current($e);
        $out = [];
        foreach (self::curriculumOf($e)['competencies'] as $c) foreach ($c['skills'] as $k => $_) $out[$k] = isset($current[$k]) ? $current[$k]->getStatus() : 'NT';
        return $out;
    }

    /** Statut d'une compétence : le moins avancé de ses éléments (V seulement si tous sont validés). */
    public static function competencyStatus(array $competency, array $statuses): string
    {
        $rank = ['NT' => 0, 'ECA' => 1, 'A' => 2, 'V' => 3];
        $values = array_map(fn ($k) => $rank[$statuses[$k] ?? 'NT'], array_keys($competency['skills']));
        $min = min($values);
        if ($min === 0 && max($values) > 0) return 'ECA'; // commencée
        return array_search($min, $rank, true);
    }

    /**
     * Raison pour laquelle $u ne peut pas poser « Validé » sur cet élément à ce lieu (null = possible).
     * Règles du MFT : niveau d'encadrement minimum du cursus, milieu naturel (N2, N3 et certaines compétences),
     * compétences d'intervention validées en fin de formation.
     */
    public function cannotValidate(User $u, Enrolment $e, string $skill, string $place, ?array $statuses = null): ?string
    {
        $cur = self::curriculumOf($e);
        $comp = Mft::competencyOf($cur, $skill);
        if (Mft::levelRank($u->getLevel()) < Mft::levelRank($cur['validateLevel'])) {
            return sprintf('La validation du %s est réservée aux encadrants %s minimum%s.', $cur['name'], $cur['validateLevel'], $u->getLevel() ? '' : ' (renseignez votre niveau d\'encadrement dans votre compte, via un administrateur)');
        }
        if (!$comp['theory'] && ($cur['naturalOnly'] || $comp['natural']) && $place !== 'natural') {
            return 'Cette compétence se valide en milieu naturel, à l\'exclusion des piscines et fosses.';
        }
        if ($comp['final']) {
            $statuses ??= $this->skillStatuses($e);
            // périmètre : sa qualification propre (PA20, PE40, PA40), pas tout le brevet
            $scope = array_diff($comp['quals'], [$cur['code']]) ?: $comp['quals'];
            $missing = [];
            foreach ($cur['competencies'] as $c) {
                if ($c['final'] || $c['theory'] || !array_intersect($c['quals'], $scope)) continue;
                if (self::competencyStatus($c, $statuses) !== 'V') $missing[] = $c['title'];
            }
            if ($missing) return 'Compétence à valider en fin de formation : il reste ' . implode(', ', $missing) . '.';
        }
        return null;
    }

    /** Enregistre une évaluation après contrôle des règles. */
    public function evaluate(User $u, Enrolment $e, string $skill, string $status, \DateTimeImmutable $date, string $place, ?string $site, ?int $depth, ?string $comment): Evaluation
    {
        $cur = self::curriculumOf($e);
        if (!Mft::competencyOf($cur, $skill)) throw new BadRequestHttpException("Élément inconnu pour le $cur[name] : $skill");
        if (!isset(Mft::STATUSES[$status])) throw new BadRequestHttpException('Statut invalide (ECA, A ou V)');
        if (!isset(Mft::PLACES[$place])) throw new BadRequestHttpException('Lieu invalide (pool, pit ou natural)');
        if ($e->getStatus() !== 'active') throw new BadRequestHttpException('Ce suivi est clôturé');
        if ($date > new \DateTimeImmutable('tomorrow')) throw new BadRequestHttpException('La date ne peut pas être dans le futur');
        if ($status === 'V' && ($why = $this->cannotValidate($u, $e, $skill, $place))) throw new AccessDeniedHttpException($why);

        $ev = (new Evaluation($e, $skill, $status, $date, $place, $u))->setSite($site)->setDepth($depth)->setComment($comment);
        $this->em->persist($ev);
        $e->getEvaluations()->add($ev);
        return $ev;
    }

    /**
     * Évaluation groupée pour un stagiaire : tous les éléments ou aucun.
     * Les règles sont vérifiées comme si les éléments étaient saisis l'un après l'autre (compétences de fin de formation
     * en dernier), ce qui permet de valider dans la même saisie une compétence et celle qui en dépend.
     * @return int nombre d'évaluations enregistrées
     */
    public function evaluateMany(User $u, Enrolment $e, array $skills, string $status, \DateTimeImmutable $date, string $place, ?string $site, ?int $depth, ?string $comment): int
    {
        $cur = self::curriculumOf($e);
        if (self::isTeaching($e)) throw new BadRequestHttpException('La formation initiateur s\'évalue par séances');
        if ($e->getStatus() !== 'active') throw new BadRequestHttpException('Suivi clôturé');
        if (!isset(Mft::STATUSES[$status])) throw new BadRequestHttpException('Statut invalide (ECA, A ou V)');
        if (!isset(Mft::PLACES[$place])) throw new BadRequestHttpException('Lieu invalide');
        if ($date > new \DateTimeImmutable('tomorrow')) throw new BadRequestHttpException('La date ne peut pas être dans le futur');
        $final = fn (string $k) => (bool) (Mft::competencyOf($cur, $k)['final'] ?? false);
        foreach ($skills as $k) if (!Mft::competencyOf($cur, $k)) throw new BadRequestHttpException("Élément inconnu pour le $cur[name]");
        usort($skills, fn ($a, $b) => $final($a) <=> $final($b));
        if ($status === 'V') {
            $statuses = $this->skillStatuses($e);
            foreach ($skills as $k) {
                if ($why = $this->cannotValidate($u, $e, $k, $place, $statuses)) throw new AccessDeniedHttpException($why);
                $statuses[$k] = 'V';
            }
        }
        foreach ($skills as $k) {
            $ev = (new Evaluation($e, $k, $status, $date, $place, $u))->setSite($site)->setDepth($depth)->setComment($comment);
            $this->em->persist($ev);
            $e->getEvaluations()->add($ev);
        }
        return count($skills);
    }

    /** Qualification délivrable ? Renvoie la raison sinon. */
    public function cannotIssue(User $u, Enrolment $e, string $qual): ?string
    {
        $cur = self::curriculumOf($e);
        if (!isset($cur['qualifications'][$qual])) return 'Qualification inconnue';
        if (isset($e->getIssued()[$qual])) return 'Déjà délivrée';
        if (self::isTeaching($e)) return $this->cannotRecordExam($u, $e);
        $mayIssue = Mft::levelRank($u->getLevel()) >= Mft::levelRank($cur['issueLevel']) || ($cur['presidentMayIssue'] && $u->canIssue());
        if (!$mayIssue) return sprintf('Délivrance réservée aux encadrants %s minimum%s.', $cur['issueLevel'], $cur['presidentMayIssue'] ? ' ou au président du club' : '');
        $statuses = $this->skillStatuses($e);
        $missing = [];
        foreach ($cur['competencies'] as $c) if (in_array($qual, $c['quals'], true) && self::competencyStatus($c, $statuses) !== 'V') $missing[] = $c['title'];
        return $missing ? 'Compétences non validées : ' . implode(', ', $missing) . '.' : null;
    }

    /** Date de la première compétence validée et date limite (15 mois) pour acquérir l'ensemble. */
    public function deadline(Enrolment $e): ?array
    {
        $cur = self::curriculumOf($e);
        if (self::isTeaching($e)) {
            // livret pédagogique valable 3 ans à partir du stage initial
            $d = $e->getDossier()['stageInitial']['date'] ?? null;
            return $d ? ['firstValidatedAt' => new \DateTimeImmutable($d), 'deadline' => (new \DateTimeImmutable($d))->modify("+{$cur['delayMonths']} months")] : null;
        }
        $statuses = [];
        $first = null;
        // une compétence est « délivrée » le jour où son dernier élément est validé
        foreach ($e->getEvaluations() as $ev) {
            $statuses[$ev->getSkill()] = $ev->getStatus();
            foreach ($cur['competencies'] as $c) {
                if (isset($c['skills'][$ev->getSkill()]) && self::competencyStatus($c, $statuses) === 'V') { $first = $ev->getDate(); break 2; }
            }
        }
        return $first ? ['firstValidatedAt' => $first, 'deadline' => $first->modify("+{$cur['delayMonths']} months")] : null;
    }

    /** Progression : nombre de compétences validées / total. */
    public function progress(Enrolment $e): array
    {
        if (self::isTeaching($e)) {
            $mods = self::curriculumOf($e)['modules'];
            $state = $e->getModules();
            $done = count(array_filter($mods, fn ($m) => isset($state[$m['key']]['validated']) || isset($state[$m['key']]['exempted'])));
            $started = count(array_filter($mods, fn ($m) => $this->sessionsOf($e, $m['key']) || isset($state[$m['key']])));
            return ['validated' => $done, 'started' => $started, 'total' => count($mods)];
        }
        $statuses = $this->skillStatuses($e);
        $comps = self::curriculumOf($e)['competencies'];
        $done = count(array_filter($comps, fn ($c) => self::competencyStatus($c, $statuses) === 'V'));
        $started = count(array_filter($comps, fn ($c) => self::competencyStatus($c, $statuses) !== 'NT'));
        return ['validated' => $done, 'started' => $started, 'total' => count($comps)];
    }

    public function present(Enrolment $e, User $viewer, bool $full = true): array
    {
        $cur = self::curriculumOf($e);
        $current = $this->current($e);
        $statuses = $this->skillStatuses($e, $current);
        $deadline = $this->deadline($e);
        $data = [
            'id' => $e->getId(), 'curriculum' => $e->getCurriculum(), 'name' => $cur['name'], 'version' => $e->getVersion(),
            'startedAt' => $e->getStartedAt()->format('Y-m-d'), 'status' => $e->getStatus(),
            'progress' => $this->progress($e),
            'firstValidatedAt' => $deadline ? $deadline['firstValidatedAt']->format('Y-m-d') : null,
            'deadline' => $deadline ? $deadline['deadline']->format('Y-m-d') : null,
            'issued' => $e->getIssued(),
        ];
        if (!$full) return $data;
        $data['prerequisites'] = array_map(function ($label) use ($e) {
            $v = $e->getPrerequisites()[$label] ?? null;
            return ['label' => $label, 'done' => !empty($v), 'date' => is_array($v) ? ($v['date'] ?? null) : null, 'by' => is_array($v) ? ($v['by'] ?? null) : null, 'note' => is_array($v) ? ($v['note'] ?? null) : null];
        }, $cur['prerequisites']);
        if (self::isTeaching($e)) {
            $c = $e->getDossier()['complement'] ?? [];
            $hours = array_sum(array_map(fn ($x) => $x['type'] === 'theory' ? $x['hours'] : 0, $c));
            $dives = count(array_filter($c, fn ($x) => $x['type'] === 'dive'));
            $data['complement'] = ['entries' => $c, 'theoryHours' => $hours, 'dives' => $dives, 'complete' => $hours >= 7 && $dives >= 4];
            $data['dossier'] = $e->getDossier();
            $data['modules'] = array_map(fn ($m) => $this->moduleState($viewer, $e, $m), $cur['modules']);
            $data['qualifications'] = array_map(fn ($q) => ['code' => $q, 'label' => $cur['qualifications'][$q], 'issued' => $e->getIssued()[$q] ?? null, 'cannotIssue' => $this->cannotIssue($viewer, $e, $q)], array_keys($cur['qualifications']));
            $data['canTutor'] = self::isTutor($viewer);
            $data += ['competencies' => [], 'skills' => new \stdClass(), 'evaluations' => []];
            return $data;
        }
        $data['competencies'] = array_map(fn ($c) => ['key' => $c['key'], 'status' => self::competencyStatus($c, $statuses)], $cur['competencies']);
        $data['skills'] = array_map(fn ($k) => [
            'status' => $statuses[$k],
            'last' => isset($current[$k]) ? self::evaluation($current[$k]) : null,
            // « Validé » possible en milieu naturel (et en piscine si les règles le permettent)
            'validate' => ['natural' => $this->cannotValidate($viewer, $e, $k, 'natural', $statuses), 'pool' => $this->cannotValidate($viewer, $e, $k, 'pool', $statuses)],
        ], array_combine(array_keys($statuses), array_keys($statuses)));
        $data['evaluations'] = array_map(self::evaluation(...), array_reverse($e->getEvaluations()->getValues()));
        $data['qualifications'] = array_map(fn ($q) => ['code' => $q, 'label' => $cur['qualifications'][$q], 'issued' => $e->getIssued()[$q] ?? null, 'cannotIssue' => $this->cannotIssue($viewer, $e, $q)], array_keys($cur['qualifications']));
        return $data;
    }

    public static function evaluation(Evaluation $ev): array
    {
        return [
            'id' => $ev->getId(), 'skill' => $ev->getSkill(), 'status' => $ev->getStatus(), 'date' => $ev->getDate()->format('Y-m-d'),
            'place' => $ev->getPlace(), 'site' => $ev->getSite(), 'depth' => $ev->getDepth(), 'comment' => $ev->getComment(),
            'instructor' => $ev->getInstructorName(), 'instructorId' => $ev->getInstructor()?->getId(),
        ];
    }

    // ---------------------------------------------------------------- Formation Initiateur

    public static function isTeaching(Enrolment $e): bool
    {
        return (self::curriculumOf($e)['type'] ?? 'diver') === 'teaching';
    }

    /** Tuteur de stage initiateur : E4, ou E3 titulaire du TSI. */
    public static function isTutor(User $u): bool
    {
        return $u->getLevel() === 'E4' || ($u->getLevel() === 'E3' && $u->isTsi());
    }

    /** @return TeachingSession[] */
    public function sessionsOf(Enrolment $e, string $module): array
    {
        return array_values(array_filter($e->getSessions()->getValues(), fn (TeachingSession $s) => $s->getModule() === $module));
    }

    /** complete : toutes les phases réalisées (aucun NT) ; validated : toutes acquises (A). */
    public static function sessionState(array $module, TeachingSession $s): array
    {
        $items = $s->getItems();
        $values = array_map(fn ($k) => $items[$k] ?? 'NT', array_keys(Mft::moduleItems($module)));
        return ['complete' => !in_array('NT', $values, true), 'validated' => !array_diff($values, ['A'])];
    }

    /** Compteurs du module : séances complètes, validées, thèmes et niveaux différents. */
    public function moduleCounts(Enrolment $e, array $module): array
    {
        $complete = $validated = 0;
        $themes = $levels = [];
        foreach ($this->sessionsOf($e, $module['key']) as $s) {
            $st = self::sessionState($module, $s);
            if (!$st['complete']) continue;
            $complete++;
            if ($st['validated']) $validated++;
            if ($s->getTheme()) $themes[mb_strtolower($s->getTheme())] = true;
            if ($s->getLevel()) $levels[$s->getLevel()] = true;
        }
        return ['sessions' => count($this->sessionsOf($e, $module['key'])), 'complete' => $complete, 'validated' => $validated, 'themes' => count($themes), 'levels' => count($levels)];
    }

    /** Raison qui empêche $u de valider le module (null = possible). */
    public function cannotValidateModule(User $u, Enrolment $e, array $module): ?string
    {
        $state = $e->getModules()[$module['key']] ?? [];
        if (isset($state['validated'])) return 'Module déjà validé';
        if (isset($state['exempted'])) return 'Module dispensé';
        if (!self::isTutor($u)) return 'Validation réservée au tuteur de stage : E4, ou E3 titulaire du TSI.';
        $c = $this->moduleCounts($e, $module);
        $missing = [];
        if ($c['complete'] < $module['minSessions']) $missing[] = sprintf('%d séance%s complète%s sur %d', $c['complete'], $c['complete'] > 1 ? 's' : '', $c['complete'] > 1 ? 's' : '', $module['minSessions']);
        if ($c['validated'] < $module['minValidated']) $missing[] = sprintf('%d séance%s validée%s sur %d', $c['validated'], $c['validated'] > 1 ? 's' : '', $c['validated'] > 1 ? 's' : '', $module['minValidated']);
        if ($module['distinct'] && $c['complete'] >= 2 && ($c['themes'] < 2 || $c['levels'] < 2)) $missing[] = 'des thèmes et des niveaux de plongeurs différents';
        foreach ($module['requirements'] as $k => $label) if (!isset($state['requirements'][$k])) $missing[] = mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1);
        return $missing ? 'Il manque : ' . implode(', ', $missing) . '.' : null;
    }

    /** Enregistrer la réussite à l'examen : référent, modules validés ou dispensés, stage initial et RIFA Plongée. */
    public function cannotRecordExam(User $u, Enrolment $e): ?string
    {
        if (!$u->isReferent()) return 'Enregistrement du résultat de l\'examen réservé aux référents.';
        $missing = [];
        foreach (self::curriculumOf($e)['modules'] as $m) {
            $st = $e->getModules()[$m['key']] ?? [];
            if (!isset($st['validated']) && !isset($st['exempted'])) $missing[] = $m['title'];
        }
        foreach ([Mft::INIT_STAGE, Mft::INIT_RIFA] as $p) if (empty($e->getPrerequisites()[$p])) $missing[] = $p;
        return $missing ? 'Il manque : ' . implode(', ', $missing) . '.' : null;
    }

    /** Enregistre une séance pédagogique : items A, ECA ou NT (absent = NT). */
    public function recordSession(User $u, Enrolment $e, string $moduleKey, \DateTimeImmutable $date, ?string $level, ?string $theme, array $items, ?string $comment): TeachingSession
    {
        $cur = self::curriculumOf($e);
        $module = Mft::module($cur, $moduleKey) ?? throw new BadRequestHttpException('Module inconnu');
        if ($e->getStatus() !== 'active') throw new BadRequestHttpException('Ce suivi est clôturé');
        $state = $e->getModules()[$moduleKey] ?? [];
        if (isset($state['validated']) || isset($state['exempted'])) throw new BadRequestHttpException('Ce module est déjà validé ou dispensé');
        if ($date > new \DateTimeImmutable('tomorrow')) throw new BadRequestHttpException('La date ne peut pas être dans le futur');
        if ($level !== null && !in_array($level, $cur['levelsTaught'], true)) throw new BadRequestHttpException('Niveau travaillé invalide');
        $allowed = Mft::moduleItems($module);
        $clean = [];
        foreach ($items as $k => $v) {
            if (!isset($allowed[$k])) throw new BadRequestHttpException("Item inconnu : $k");
            if (!in_array($v, ['A', 'ECA', 'NT'], true)) throw new BadRequestHttpException('Annotation invalide (A, ECA ou NT)');
            $clean[$k] = $v;
        }
        if (!array_diff($clean, ['NT'])) throw new BadRequestHttpException('Notez au moins un item (A ou ECA)');
        $s = (new TeachingSession($e, $moduleKey, $date, $u))->setLevel($level)->setTheme($theme)->setItems($clean)->setComment($comment);
        $this->em->persist($s);
        $e->getSessions()->add($s);
        return $s;
    }

    private function moduleState(User $viewer, Enrolment $e, array $module): array
    {
        $state = $e->getModules()[$module['key']] ?? [];
        return [
            'key' => $module['key'],
            'validated' => $state['validated'] ?? null, 'exempted' => $state['exempted'] ?? null, 'requirements' => (object) ($state['requirements'] ?? []),
            'counts' => $this->moduleCounts($e, $module),
            'cannotValidate' => $this->cannotValidateModule($viewer, $e, $module),
            'sessions' => array_map(fn (TeachingSession $s) => [
                'id' => $s->getId(), 'date' => $s->getDate()->format('Y-m-d'), 'level' => $s->getLevel(), 'theme' => $s->getTheme(), 'items' => (object) $s->getItems(),
                'comment' => $s->getComment(), 'instructor' => $s->getInstructorName(), 'instructorId' => $s->getInstructor()?->getId(),
            ] + self::sessionState($module, $s), array_reverse($this->sessionsOf($e, $module['key']))),
        ];
    }

    /** Fiche en lecture seule, pour le lien envoyé au stagiaire : sans prérogatives ni identifiants internes. */
    public function publicFiche(Diver $d, ?int $enrolmentId = null): array
    {
        $nobody = new User();
        $enrolments = [];
        foreach ($d->getEnrolments() as $e) {
            if ($enrolmentId && $e->getId() !== $enrolmentId) continue;
            $p = $this->present($e, $nobody);
            foreach ($p['skills'] as $k => $sk) unset($p['skills'][$k]['validate']);
            $p['evaluations'] = array_map(fn ($ev) => array_diff_key($ev, ['instructorId' => 1]), $p['evaluations']);
            $p['qualifications'] = array_map(fn ($q) => array_diff_key($q, ['cannotIssue' => 1]), $p['qualifications']);
            if (isset($p['modules'])) {
                $p['modules'] = array_map(function ($m) {
                    unset($m['cannotValidate']);
                    $m['sessions'] = array_map(fn ($s) => array_diff_key($s, ['instructorId' => 1]), $m['sessions']);
                    return $m;
                }, $p['modules']);
            }
            unset($p['canTutor']);
            $enrolments[] = $p;
        }
        return [
            'firstName' => $d->getFirstName(), 'lastName' => $d->getLastName(), 'licence' => $d->getLicence(),
            'sections' => array_map(fn (Section $s) => ['name' => $s->getName(), 'color' => $s->getColor()], $this->sectionsOf($d)),
            'enrolments' => $enrolments,
        ];
    }
}
