<?php
namespace App\Controller;

use App\Entity\{Diver, Enrolment, Evaluation, Section};
use App\Service\Presenter;
use App\Training\{Mft, TrainingService};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, BadRequestHttpException, ConflictHttpException};
use Symfony\Component\Routing\Attribute\Route;

/**
 * Suivi des stagiaires selon le MFT : référentiel, fiches, inscriptions aux cursus, évaluations et délivrance.
 * Consultation : tous les encadrants. Évaluation : encadrants des sections du stagiaire et référents.
 */
class TraineeController extends ApiController
{
    public function __construct(private TrainingService $training, private EntityManagerInterface $em) {}

    /** Référentiel des cursus (N1, N2, N3) et valeurs possibles. */
    #[Route('/api/mft', methods: 'GET')]
    public function referential(): JsonResponse
    {
        return $this->json(['curricula' => array_values(Mft::curricula()), 'levels' => Mft::LEVELS, 'places' => Mft::PLACES, 'statuses' => Mft::STATUSES]);
    }

    #[Route('/api/trainees', methods: 'GET')]
    public function list(Request $req): JsonResponse
    {
        $archived = $req->query->getBoolean('archived');
        $sections = [];
        foreach ($this->em->getRepository(Section::class)->findBy([], ['name' => 'ASC']) as $s) {
            foreach ($s->getDivers() as $d) $sections[$d->getId()][] = ['id' => $s->getId(), 'name' => $s->getName(), 'color' => $s->getColor()];
        }
        $divers = $this->em->getRepository(Diver::class)->findBy(['archived' => $archived], ['lastName' => 'ASC', 'firstName' => 'ASC']);
        return $this->json(array_map(fn (Diver $d) => $this->diver($d) + [
            'sections' => $sections[$d->getId()] ?? [],
            'enrolments' => array_map(fn (Enrolment $e) => $this->training->present($e, $this->getUser(), false), $d->getEnrolments()->getValues()),
        ], $divers));
    }

    /** {"firstName","lastName","licence"?, "sectionId"?, "curriculum"?} */
    #[Route('/api/trainees', methods: 'POST')]
    public function create(Request $req): JsonResponse
    {
        $d = $this->body($req);
        $diver = (new Diver())->setFirstName($this->text($d['firstName'] ?? '', 80, 'Prénom', true))->setLastName($this->text($d['lastName'] ?? '', 80, 'Nom', true))
            ->setLicence($this->text($d['licence'] ?? null, 30, 'N° de licence'));
        $section = null;
        if (!empty($d['sectionId'])) {
            $section = $this->em->find(Section::class, $d['sectionId']) ?? throw $this->createNotFoundException('Section introuvable');
            if (!$this->getUser()->isReferent() && !$section->getInstructors()->contains($this->getUser())) throw new AccessDeniedHttpException('Vous n\'encadrez pas cette section');
        } elseif (!$this->getUser()->isReferent()) {
            throw new BadRequestHttpException('Choisissez une de vos sections');
        }
        $this->em->persist($diver);
        $section?->addDiver($diver);
        if (!empty($d['curriculum'])) $this->em->persist($this->newEnrolment($diver, $d['curriculum'], $d['startedAt'] ?? null));
        $this->em->flush();
        return $this->json($this->fiche($diver), 201);
    }

    #[Route('/api/trainees/{id}', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(Diver $diver): JsonResponse
    {
        return $this->json($this->fiche($diver));
    }

    /** {"firstName"?, "lastName"?, "licence"?, "archived"?} */
    #[Route('/api/trainees/{id}', methods: 'PUT', requirements: ['id' => '\d+'])]
    public function update(Diver $diver, Request $req): JsonResponse
    {
        $this->training->assertCanEvaluate($this->getUser(), $diver);
        $d = $this->body($req);
        array_key_exists('firstName', $d) && $diver->setFirstName($this->text($d['firstName'], 80, 'Prénom', true));
        array_key_exists('lastName', $d) && $diver->setLastName($this->text($d['lastName'], 80, 'Nom', true));
        array_key_exists('licence', $d) && $diver->setLicence($this->text($d['licence'], 30, 'N° de licence'));
        array_key_exists('archived', $d) && $diver->setArchived((bool) $d['archived']);
        $this->em->flush();
        return $this->json($this->fiche($diver));
    }

    /** Inscrit le stagiaire à un cursus : {"curriculum":"N2","startedAt":"2026-09-15"} */
    #[Route('/api/trainees/{id}/enrolments', methods: 'POST', requirements: ['id' => '\d+'])]
    public function enrol(Diver $diver, Request $req): JsonResponse
    {
        $this->training->assertCanEvaluate($this->getUser(), $diver);
        $d = $this->body($req);
        $this->em->persist($this->newEnrolment($diver, (string) ($d['curriculum'] ?? ''), $d['startedAt'] ?? null));
        $this->em->flush();
        return $this->json($this->fiche($diver), 201);
    }

    /** {"prerequisites"?: {"libellé": true}, "status"?: "active"|"abandoned", "startedAt"?} */
    #[Route('/api/enrolments/{id}', methods: 'PUT', requirements: ['id' => '\d+'])]
    public function updateEnrolment(Enrolment $e, Request $req): JsonResponse
    {
        $this->training->assertCanEvaluate($this->getUser(), $e->getDiver());
        $d = $this->body($req);
        if (isset($d['prerequisites'])) {
            $allowed = TrainingService::curriculumOf($e)['prerequisites'];
            $p = $e->getPrerequisites();
            foreach ((array) $d['prerequisites'] as $label => $done) {
                if (!in_array($label, $allowed, true)) throw new BadRequestHttpException("Prérequis inconnu : $label");
                if ($done) $p[$label] = true; else unset($p[$label]);
            }
            $e->setPrerequisites($p);
        }
        if (isset($d['status'])) {
            if (!in_array($d['status'], ['active', 'abandoned'], true)) throw new BadRequestHttpException('Statut invalide');
            if ($e->getStatus() === 'done') throw new BadRequestHttpException('Ce cursus est délivré');
            $e->setStatus($d['status']);
        }
        if (!empty($d['startedAt'])) $e->setStartedAt($this->day($d['startedAt']));
        $this->em->flush();
        return $this->json($this->fiche($e->getDiver()));
    }

    /** Supprime un suivi sans évaluation (inscription par erreur). Référents seulement. */
    #[Route('/api/enrolments/{id}', methods: 'DELETE', requirements: ['id' => '\d+'])]
    public function deleteEnrolment(Enrolment $e): JsonResponse
    {
        if (!$this->getUser()->isReferent()) throw new AccessDeniedHttpException('Réservé aux référents');
        if (!$e->getEvaluations()->isEmpty()) throw new ConflictHttpException('Ce suivi contient des évaluations : marquez-le plutôt « abandonné »');
        $diver = $e->getDiver();
        $diver->getEnrolments()->removeElement($e);
        $this->em->remove($e);
        $this->em->flush();
        return $this->json($this->fiche($diver));
    }

    /**
     * Enregistre une évaluation, pour un ou plusieurs éléments :
     * {"skills":["N1.ventiler.masque"], "status":"A", "date":"2026-11-23", "place":"pool", "site"?, "depth"?, "comment"?}
     */
    #[Route('/api/enrolments/{id}/evaluations', methods: 'POST', requirements: ['id' => '\d+'])]
    public function evaluate(Enrolment $e, Request $req): JsonResponse
    {
        $this->training->assertCanEvaluate($this->getUser(), $e->getDiver());
        $d = $this->body($req);
        $skills = array_values(array_unique((array) ($d['skills'] ?? (isset($d['skill']) ? [$d['skill']] : []))));
        if (!$skills) throw new BadRequestHttpException('Choisissez au moins un élément');
        $depth = isset($d['depth']) && $d['depth'] !== '' && $d['depth'] !== null ? (int) $d['depth'] : null;
        if ($depth !== null && ($depth < 0 || $depth > 120)) throw new BadRequestHttpException('Profondeur invalide');
        $date = $this->day($d['date'] ?? 'today');
        foreach ($skills as $skill) {
            $this->training->evaluate($this->getUser(), $e, (string) $skill, (string) ($d['status'] ?? ''), $date, (string) ($d['place'] ?? ''),
                $this->text($d['site'] ?? null, 120, 'Lieu'), $depth, $this->text($d['comment'] ?? null, 2000, 'Commentaire'));
        }
        $this->em->flush();
        return $this->json($this->fiche($e->getDiver()), 201);
    }

    /** Supprime une évaluation (erreur de saisie) : son auteur ou un référent. */
    #[Route('/api/evaluations/{id}', methods: 'DELETE', requirements: ['id' => '\d+'])]
    public function deleteEvaluation(Evaluation $ev): JsonResponse
    {
        $u = $this->getUser();
        if (!$u->isReferent() && $ev->getInstructor() !== $u) throw new AccessDeniedHttpException('Seul l\'auteur de l\'évaluation ou un référent peut la supprimer');
        $e = $ev->getEnrolment();
        if ($e->getStatus() === 'done') throw new BadRequestHttpException('Ce cursus est délivré');
        $e->getEvaluations()->removeElement($ev);
        $this->em->remove($ev);
        $this->em->flush();
        return $this->json($this->fiche($e->getDiver()));
    }

    /** Délivre une qualification ou le brevet : {"qualification":"PA20","date":"2026-11-23"} */
    #[Route('/api/enrolments/{id}/issue', methods: 'POST', requirements: ['id' => '\d+'])]
    public function issue(Enrolment $e, Request $req): JsonResponse
    {
        $d = $this->body($req);
        $q = (string) ($d['qualification'] ?? '');
        if ($why = $this->training->cannotIssue($this->getUser(), $e, $q)) throw new AccessDeniedHttpException($why);
        $issued = $e->getIssued();
        $issued[$q] = ['date' => $this->day($d['date'] ?? 'today')->format('Y-m-d'), 'by' => $this->getUser()->getName()];
        $e->setIssued($issued);
        // le brevet (qualification portant le code du cursus) clôt le suivi
        if ($q === $e->getCurriculum()) $e->setStatus('done');
        $this->em->flush();
        return $this->json($this->fiche($e->getDiver()));
    }

    /** Annule une délivrance saisie par erreur (référents). */
    #[Route('/api/enrolments/{id}/issue/{qualification}', methods: 'DELETE', requirements: ['id' => '\d+'])]
    public function unissue(Enrolment $e, string $qualification): JsonResponse
    {
        if (!$this->getUser()->isReferent()) throw new AccessDeniedHttpException('Réservé aux référents');
        $issued = $e->getIssued();
        unset($issued[$qualification]);
        $e->setIssued($issued);
        if ($qualification === $e->getCurriculum() && $e->getStatus() === 'done') $e->setStatus('active');
        $this->em->flush();
        return $this->json($this->fiche($e->getDiver()));
    }

    // ---------------------------------------------------------------

    private function newEnrolment(Diver $diver, string $code, mixed $startedAt): Enrolment
    {
        $cur = Mft::curriculum($code) ?? throw new BadRequestHttpException('Cursus inconnu (N1, N2 ou N3)');
        foreach ($diver->getEnrolments() as $x) if ($x->getCurriculum() === $code) throw new ConflictHttpException("Le stagiaire suit déjà le $cur[name]");
        $e = new Enrolment($diver, $code, $cur['version'], $startedAt ? $this->day($startedAt) : new \DateTimeImmutable('today'));
        $diver->getEnrolments()->add($e);
        return $e;
    }

    private function fiche(Diver $d): array
    {
        $u = $this->getUser();
        return $this->diver($d) + [
            'sections' => array_map(fn (Section $s) => ['id' => $s->getId(), 'name' => $s->getName(), 'color' => $s->getColor()], $this->training->sectionsOf($d)),
            'canEvaluate' => $this->training->canEvaluate($u, $d),
            'enrolments' => array_map(fn (Enrolment $e) => $this->training->present($e, $u), $d->getEnrolments()->getValues()),
        ];
    }

    private function diver(Diver $d): array
    {
        return Presenter::diver($d) + ['licence' => $d->getLicence(), 'archived' => $d->isArchived()];
    }

    private function day(mixed $v): \DateTimeImmutable
    {
        if ($v === 'today') return new \DateTimeImmutable('today');
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new BadRequestHttpException('Date invalide (AAAA-MM-JJ)');
        return new \DateTimeImmutable($v);
    }

    private function text(mixed $v, int $max, string $label, bool $required = false): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            if ($required) throw new BadRequestHttpException("$label requis");
            return null;
        }
        if (mb_strlen($v) > $max) throw new BadRequestHttpException("$label : $max caractères maximum");
        return $v;
    }
}
