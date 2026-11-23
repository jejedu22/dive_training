<?php
namespace App\Training;

use App\Entity\{CurriculumDefinition, Enrolment, Evaluation, TeachingSession};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\{RequestEvent, TerminateEvent};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Référentiel des cursus modifiable par les administrateurs.
 * Les valeurs par défaut viennent des manuels FFESSM (Mft::defaults()) ; un cursus modifié ou créé est enregistré
 * en base (CurriculumDefinition). Les clés des éléments ne changent jamais : les évaluations y font référence.
 * Un élément, une compétence ou un module déjà évalué ne peut pas être supprimé.
 */
final class Referential
{
    public function __construct(private EntityManagerInterface $em) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) $this->install();
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        Mft::setLoader(null);
    }

    public function install(): void
    {
        Mft::setLoader(function () {
            try {
                return $this->load();
            } catch (\Throwable) {
                return Mft::defaults(); // base indisponible (installation) : valeurs du MFT
            }
        });
    }

    /** @return array<string, array> cursus par défaut (éventuellement modifiés) puis cursus créés par le club */
    public function load(): array
    {
        $all = Mft::defaults();
        foreach ($this->em->getRepository(CurriculumDefinition::class)->findBy([], ['code' => 'ASC']) as $d) {
            $all[$d->getCode()] = $d->getData() + ['code' => $d->getCode()];
        }
        return $all;
    }

    public function definition(string $code): ?CurriculumDefinition
    {
        return $this->em->find(CurriculumDefinition::class, $code);
    }

    public function isDefault(string $code): bool
    {
        return isset(Mft::defaults()[$code]);
    }

    /** Utilisation du cursus : nombre de suivis, évaluations par élément, séances par module, exigences enregistrées. */
    public function usage(string $code): array
    {
        $enrolments = $this->em->getRepository(Enrolment::class)->findBy(['curriculum' => $code]);
        $skills = [];
        foreach ($this->em->createQuery('SELECT ev.skill AS skill, COUNT(ev.id) AS n FROM ' . Evaluation::class . ' ev JOIN ev.enrolment e WHERE e.curriculum = :c GROUP BY ev.skill')
            ->setParameter('c', $code)->getArrayResult() as $r) $skills[$r['skill']] = (int) $r['n'];
        $modules = $requirements = [];
        foreach ($this->em->createQuery('SELECT s FROM ' . TeachingSession::class . ' s JOIN s.enrolment e WHERE e.curriculum = :c')->setParameter('c', $code)->getResult() as $s) {
            $modules[$s->getModule()] = ($modules[$s->getModule()] ?? 0) + 1;
        }
        foreach ($enrolments as $e) {
            foreach ($e->getModules() as $m => $st) {
                if ($st) $modules[$m] = $modules[$m] ?? 0;
                foreach (array_keys($st['requirements'] ?? []) as $k) $requirements["$m.$k"] = ($requirements["$m.$k"] ?? 0) + 1;
            }
        }
        return ['enrolments' => count($enrolments), 'skills' => $skills, 'modules' => $modules, 'requirements' => $requirements];
    }

    /** Enregistre un cursus modifié (après contrôle). */
    public function save(string $code, array $input, ?string $by): array
    {
        $current = Mft::curriculum($code) ?? throw new BadRequestHttpException('Cursus inconnu');
        $clean = $this->normalize($code, $input, $current);
        $def = $this->definition($code) ?? new CurriculumDefinition($code);
        $def->setData($clean, $by);
        $this->em->persist($def);
        $this->em->flush();
        $this->install();
        return $clean;
    }

    /** Crée un cursus du club à partir d'un cursus existant. */
    public function create(string $code, string $copyFrom, string $name, ?string $by): array
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) throw new BadRequestHttpException('Code du cursus : 2 à 10 lettres majuscules ou chiffres (ex. N4, NITROX)');
        if (Mft::curriculum($code)) throw new ConflictHttpException('Ce code de cursus existe déjà');
        $src = Mft::curriculum($copyFrom) ?? throw new BadRequestHttpException('Cursus à copier inconnu');
        $data = $this->rekey($src, $copyFrom, $code);
        $data['name'] = trim($name) !== '' ? trim($name) : $code;
        $def = (new CurriculumDefinition($code))->setData($this->normalize($code, $data, null), $by);
        $this->em->persist($def);
        $this->em->flush();
        $this->install();
        return $def->getData();
    }

    /** Cursus par défaut : revient aux valeurs du MFT. Cursus du club : supprimé (s'il n'a aucun suivi). */
    public function reset(string $code): void
    {
        $usage = $this->usage($code);
        if (!$this->isDefault($code)) {
            if ($usage['enrolments']) throw new ConflictHttpException('Ce cursus a des stagiaires : il ne peut pas être supprimé');
        } else {
            // les éléments déjà évalués doivent exister dans le référentiel du MFT
            $default = Mft::defaults()[$code];
            $keys = [];
            foreach ($default['competencies'] as $c) $keys += $c['skills'];
            $missing = array_diff(array_keys($usage['skills']), array_keys($keys));
            if ($missing) throw new ConflictHttpException('Impossible de revenir au MFT : des évaluations portent sur des éléments ajoutés par le club (' . count($missing) . ')');
            foreach (array_keys($usage['modules']) as $m) if (!Mft::module($default, $m)) throw new ConflictHttpException('Impossible de revenir au MFT : des séances portent sur un module ajouté par le club');
        }
        if ($def = $this->definition($code)) {
            $this->em->remove($def);
            $this->em->flush();
        }
        $this->install();
    }

    // ------------------------------------------------------------------

    /** Contrôle et nettoie la définition saisie ; génère les clés des nouveaux éléments. */
    private function normalize(string $code, array $in, ?array $current): array
    {
        $type = $current['type'] ?? ($in['type'] ?? 'diver');
        $usage = $current ? $this->usage($code) : ['skills' => [], 'modules' => [], 'requirements' => [], 'enrolments' => 0];
        $out = [
            'type' => $type, 'code' => $code,
            'name' => $this->text($in['name'] ?? '', 60, 'Nom du cursus', true),
            'subtitle' => $this->text($in['subtitle'] ?? '', 160, 'Sous-titre') ?? '',
            'version' => $this->text($in['version'] ?? '', 40, 'Version du référentiel') ?? '',
            'validateLevel' => $this->level($in['validateLevel'] ?? 'E1', 'Niveau pour valider'),
            'issueLevel' => $this->level($in['issueLevel'] ?? 'E3', 'Niveau pour délivrer'),
            'presidentMayIssue' => (bool) ($in['presidentMayIssue'] ?? false),
            'naturalOnly' => (bool) ($in['naturalOnly'] ?? false),
            'delayMonths' => $this->int($in['delayMonths'] ?? 15, 1, 120, 'Délai (mois)'),
            'prerequisites' => $this->lines($in['prerequisites'] ?? [], 160, 'Prérequis'),
            'notes' => $this->lines($in['notes'] ?? [], 300, 'Remarque'),
        ];
        // qualifications : la qualification portant le code du cursus (le brevet) clôt le suivi
        $quals = [];
        foreach ((array) ($in['qualifications'] ?? []) as $q => $label) {
            $q = strtoupper(trim((string) $q));
            if (!preg_match('/^[A-Z0-9]{2,10}$/', $q)) throw new BadRequestHttpException("Code de qualification invalide : $q");
            $quals[$q] = $this->text($label, 80, 'Nom de qualification', true);
        }
        if (!isset($quals[$code])) throw new BadRequestHttpException("La qualification « $code » (le brevet du cursus) est obligatoire");
        $out['qualifications'] = $quals;

        if ($type === 'teaching') {
            foreach ([Mft::INIT_STAGE, Mft::INIT_RIFA] as $locked) {
                if (!in_array($locked, $out['prerequisites'], true)) throw new BadRequestHttpException("Le prérequis « $locked » est utilisé par les règles de l'examen : il ne peut pas être modifié ni supprimé");
            }
            $out['tutor'] = $this->text($in['tutor'] ?? '', 120, 'Tuteur') ?? '';
            $out['levelsTaught'] = $this->lines($in['levelsTaught'] ?? [], 30, 'Niveau travaillé');
            if (!$out['levelsTaught']) throw new BadRequestHttpException('Au moins un niveau travaillé');
            $out['competencies'] = [];
            $out['modules'] = $this->modules((array) ($in['modules'] ?? []), $current, $usage);
        } else {
            $out['competencies'] = $this->competencies($code, (array) ($in['competencies'] ?? []), $current, $usage, array_keys($quals));
        }
        return $out;
    }

    private function competencies(string $code, array $list, ?array $current, array $usage, array $quals): array
    {
        if (!$list) throw new BadRequestHttpException('Au moins une compétence');
        $known = [];
        foreach ($current['competencies'] ?? [] as $c) $known[$c['key']] = array_keys($c['skills']);
        $out = $taken = $kept = [];
        foreach ($list as $i => $c) {
            $title = $this->text($c['title'] ?? '', 120, 'Compétence ' . ($i + 1), true);
            $key = isset($c['key'], $known[$c['key']]) ? $c['key'] : $this->key($code, $title, $taken);
            $taken[$key] = true;
            $q = array_values(array_intersect($quals, (array) ($c['quals'] ?? [])));
            if (!$q) throw new BadRequestHttpException("« $title » : choisissez au moins une qualification");
            $skills = [];
            foreach ((array) ($c['skills'] ?? []) as $j => $sk) {
                $label = $this->text(is_array($sk) ? ($sk['label'] ?? '') : $sk, 160, "« $title », élément " . ($j + 1), true);
                $sKey = is_array($sk) && isset($sk['key']) && in_array($sk['key'], $known[$key] ?? [], true) ? $sk['key'] : $this->key($key, $label, $skills);
                $skills[$sKey] = $label;
            }
            if (!$skills) throw new BadRequestHttpException("« $title » : au moins un élément");
            $kept += $skills;
            $out[] = ['key' => $key, 'title' => $title, 'group' => $this->text($c['group'] ?? null, 60, 'Groupe'), 'quals' => $q, 'skills' => $skills,
                'final' => (bool) ($c['final'] ?? false), 'theory' => (bool) ($c['theory'] ?? false), 'natural' => (bool) ($c['natural'] ?? false)];
        }
        foreach ($usage['skills'] as $k => $n) {
            if (!isset($kept[$k])) throw new ConflictHttpException(sprintf('Un élément évalué %d fois ne peut pas être supprimé (%s)', $n, $this->labelOf($current, $k)));
        }
        return $out;
    }

    private function modules(array $list, ?array $current, array $usage): array
    {
        if (!$list) throw new BadRequestHttpException('Au moins un module');
        $known = [];
        foreach ($current['modules'] ?? [] as $m) $known[$m['key']] = $m;
        $out = $taken = [];
        foreach ($list as $i => $m) {
            $title = $this->text($m['title'] ?? '', 120, 'Module ' . ($i + 1), true);
            $key = isset($m['key'], $known[$m['key']]) ? $m['key'] : null;
            if (!$key) { $n = 1; while (isset($known["m$n"]) || isset($taken["m$n"])) $n++; $key = "m$n"; }
            $taken[$key] = true;
            $knownItems = $key && isset($known[$key]) ? Mft::moduleItems($known[$key]) : [];
            $phases = $itemKeys = [];
            foreach ((array) ($m['phases'] ?? []) as $p) {
                $name = $this->text($p['name'] ?? '', 40, "« $title » : phase", true);
                $items = [];
                foreach ((array) ($p['items'] ?? []) as $it) {
                    $label = $this->text($it['label'] ?? '', 160, "« $title » : item", true);
                    $k = isset($it['key'], $knownItems[$it['key']]) ? $it['key'] : $this->key('', $label, $itemKeys);
                    $items[$k] = $label;
                    $itemKeys[$k] = true;
                }
                if (!$items) throw new BadRequestHttpException("« $title », phase « $name » : au moins un item");
                if (isset($phases[$name])) throw new BadRequestHttpException("« $title » : deux phases portent le nom « $name »");
                $phases[$name] = $items;
            }
            if (!$phases) throw new BadRequestHttpException("« $title » : au moins une phase");
            $reqs = [];
            foreach ((array) ($m['requirements'] ?? []) as $r) {
                $label = $this->text($r['label'] ?? '', 160, "« $title » : exigence", true);
                $k = isset($r['key'], $known[$key]['requirements'][$r['key']]) ? $r['key'] : $this->key('', $label, $reqs);
                $reqs[$k] = $label;
            }
            foreach ($usage['requirements'] as $rk => $n) {
                [$mk, $k] = explode('.', $rk, 2);
                if ($mk === $key && !isset($reqs[$k])) throw new ConflictHttpException("« $title » : une exigence déjà enregistrée ne peut pas être supprimée");
            }
            $min = $this->int($m['minSessions'] ?? 1, 0, 50, "« $title » : séances complètes");
            $out[] = ['key' => $key, 'title' => $title, 'minSessions' => $min, 'minValidated' => $this->int($m['minValidated'] ?? 0, 0, $min ?: 50, "« $title » : séances validées"),
                'distinct' => (bool) ($m['distinct'] ?? false), 'exemptable' => $this->text($m['exemptable'] ?? null, 300, 'Dispense'), 'help' => $this->text($m['help'] ?? null, 300, 'Aide'),
                'phases' => $phases, 'requirements' => $reqs];
        }
        foreach ($usage['modules'] as $k => $_) {
            if (!isset($taken[$k])) throw new ConflictHttpException(sprintf('Le module « %s » a des séances : il ne peut pas être supprimé', $known[$k]['title'] ?? $k));
        }
        return $out;
    }

    /** Copie d'un cursus sous un autre code (clés préfixées par le nouveau code). */
    private function rekey(array $src, string $from, string $to): array
    {
        $src['code'] = $to;
        $quals = [];
        foreach ($src['qualifications'] as $q => $l) $quals[$q === $from ? $to : $q] = $l;
        $src['qualifications'] = $quals;
        $src['competencies'] = array_map(function ($c) use ($from, $to) {
            $c['key'] = null; // nouvelles clés
            $c['quals'] = array_map(fn ($q) => $q === $from ? $to : $q, $c['quals']);
            $c['skills'] = array_map(fn ($l) => ['label' => $l], array_values($c['skills']));
            return $c;
        }, $src['competencies']);
        if (isset($src['modules'])) {
            $src['modules'] = array_map(function ($m) {
                $m['phases'] = array_map(fn ($name, $items) => ['name' => $name, 'items' => array_map(fn ($l) => ['label' => $l], array_values($items))], array_keys($m['phases']), $m['phases']);
                $m['requirements'] = array_map(fn ($l) => ['label' => $l], array_values($m['requirements']));
                return $m;
            }, $src['modules']);
        }
        return $src;
    }

    private function labelOf(?array $cur, string $skill): string
    {
        $c = $cur ? Mft::competencyOf($cur, $skill) : null;
        return $c ? $c['title'] . ' — ' . $c['skills'][$skill] : $skill;
    }

    /** Clé lisible et unique : préfixe.slug (ex. N1.ventiler.masque). */
    private function key(string $prefix, string $label, array $taken): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $label)));
        $slug = substr($slug, 0, 18) ?: 'x';
        $base = ($prefix !== '' ? "$prefix." : '') . $slug;
        $k = $base;
        for ($i = 2; isset($taken[$k]) || ($prefix !== '' && strlen($k) > 60); $i++) $k = substr($base, 0, 56) . $i;
        return $k;
    }

    private function text(mixed $v, int $max, string $label, bool $required = false): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            if ($required) throw new BadRequestHttpException("$label : requis");
            return null;
        }
        if (mb_strlen($v) > $max) throw new BadRequestHttpException("$label : $max caractères maximum");
        return $v;
    }

    private function lines(mixed $v, int $max, string $label): array
    {
        $out = [];
        foreach ((array) $v as $line) if (($t = $this->text($line, $max, $label)) !== null) $out[] = $t;
        return array_values(array_unique($out));
    }

    private function level(mixed $v, string $label): string
    {
        if (!in_array($v, Mft::LEVELS, true)) throw new BadRequestHttpException("$label : E1 à E4");
        return $v;
    }

    private function int(mixed $v, int $min, int $max, string $label): int
    {
        if (!is_numeric($v) || (int) $v < $min || (int) $v > $max) throw new BadRequestHttpException("$label : entre $min et $max");
        return (int) $v;
    }
}
