<?php
namespace App\Training;

use App\Entity\{Diver, Enrolment, Evaluation, Section, User};
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

    /** Qualification délivrable ? Renvoie la raison sinon. */
    public function cannotIssue(User $u, Enrolment $e, string $qual): ?string
    {
        $cur = self::curriculumOf($e);
        if (!isset($cur['qualifications'][$qual])) return 'Qualification inconnue';
        if (isset($e->getIssued()[$qual])) return 'Déjà délivrée';
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
        $data['prerequisites'] = array_map(fn ($label) => ['label' => $label, 'done' => !empty($e->getPrerequisites()[$label])], $cur['prerequisites']);
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
}
