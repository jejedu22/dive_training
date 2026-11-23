<?php
namespace App\Controller;

use App\Entity\ShareLink;
use App\Service\{PlanningPdf, PlanningService, SlotService, TraineePdf};
use App\Training\TrainingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, HttpException, NotFoundHttpException, TooManyRequestsHttpException};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Liens de partage du planning.
 * - Gestion (référents) : /api/share-links
 * - Consultation publique, sans compte : /api/public/{token}, /planning, /pdf (lecture seule, sans élèves ni bouteilles)
 *
 * Sécurité : jeton aléatoire de 192 bits, révocable, date d'expiration et code d'accès facultatifs.
 * Le code est envoyé dans l'en-tête X-Share-Code (jamais dans l'adresse) ; après 5 codes faux le lien est bloqué 15 min.
 * Les réponses publiques ne sont ni mises en cache ni indexées.
 */
class ShareController extends ApiController
{
    #[Route('/api/share-links', methods: 'GET'), IsGranted('ROLE_REFERENT')]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        return $this->json(array_map(fn (ShareLink $l) => $this->present($l), $em->getRepository(ShareLink::class)->findBy(['diver' => null], ['createdAt' => 'DESC'])));
    }

    /** {"label":"Piscine municipale","expiresAt":"2027-06-30"|null,"code":"1234"|null} */
    #[Route('/api/share-links', methods: 'POST'), IsGranted('ROLE_REFERENT')]
    public function create(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $label = $d['label'] ?? null;
        if ($label !== null && mb_strlen(trim((string) $label)) > 120) throw new BadRequestHttpException('Nom : 120 caractères maximum');
        $link = (new ShareLink())->setLabel($label)->setCreatedBy($this->getUser())->setCode($this->code($d['code'] ?? null));
        if (!empty($d['expiresAt'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['expiresAt'])) throw new BadRequestHttpException('Date d\'expiration invalide (AAAA-MM-JJ)');
            $link->setExpiresAt(new \DateTimeImmutable($d['expiresAt']));
        }
        $em->persist($link);
        $em->flush();
        return $this->json($this->present($link), 201);
    }

    /** Change ou retire le code d'accès : {"code":"5678"} ou {"code":null}. */
    #[Route('/api/share-links/{id}', methods: 'PUT', requirements: ['id' => '\d+'])]
    public function update(ShareLink $link, Request $req, EntityManagerInterface $em, TrainingService $training): JsonResponse
    {
        $u = $this->getUser();
        if (!$u->isReferent() && !($link->getDiver() && $training->canEvaluate($u, $link->getDiver()))) throw $this->createAccessDeniedException('Réservé aux référents');
        $d = $this->body($req);
        if (array_key_exists('code', $d)) $link->setCode($this->code($d['code']));
        $em->flush();
        return $this->json($this->present($link));
    }

    /** Révoque le lien : l'adresse ne fonctionne plus. */
    #[Route('/api/share-links/{id}', methods: 'DELETE', requirements: ['id' => '\d+'])]
    public function delete(ShareLink $link, EntityManagerInterface $em, TrainingService $training): JsonResponse
    {
        $u = $this->getUser();
        if (!$u->isReferent() && !($link->getDiver() && $training->canEvaluate($u, $link->getDiver()))) throw $this->createAccessDeniedException('Réservé aux référents');
        $em->remove($link);
        $em->flush();
        return $this->json(null, 204);
    }

    // ---------- accès public (sans compte)

    #[Route('/api/public/{token}', methods: 'GET')]
    public function info(string $token, Request $req, EntityManagerInterface $em, SlotService $slots): JsonResponse
    {
        $link = $this->valid($token, $req, $em, $slots);
        $link->markOpened();
        $em->flush();
        $d = $link->getDiver();
        return $this->private($this->json(['label' => $link->getLabel(), 'expiresAt' => $link->getExpiresAt()?->format('Y-m-d'),
            'kind' => $d ? 'trainee' : 'planning', 'name' => $d?->getFullName()]));
    }

    #[Route('/api/public/{token}/planning', methods: 'GET')]
    public function planning(string $token, Request $req, EntityManagerInterface $em, SlotService $slots, PlanningService $planning): JsonResponse
    {
        $this->valid($token, $req, $em, $slots, 'planning');
        [$from, $to] = $this->period($req);
        return $this->private($this->json($planning->build($from, $to, null, false)));
    }

    #[Route('/api/public/{token}/pdf', methods: 'GET')]
    public function pdf(string $token, Request $req, EntityManagerInterface $em, SlotService $slots, PlanningService $planning, PlanningPdf $pdf): Response
    {
        $this->valid($token, $req, $em, $slots, 'planning');
        [$from, $to] = $this->period($req);
        return $this->private($pdf->response($planning->build($from, $to, null, false), (string) $req->query->get('title', 'Planning des lignes d\'eau'), $from));
    }

    /** Livret du stagiaire (lien envoyé à la demande) : lecture seule. */
    #[Route('/api/public/{token}/trainee', methods: 'GET')]
    public function trainee(string $token, Request $req, EntityManagerInterface $em, SlotService $slots, TrainingService $training): JsonResponse
    {
        $link = $this->valid($token, $req, $em, $slots, 'trainee');
        $fiche = $training->publicFiche($link->getDiver());
        // intitulés des cursus suivis (la page publique n'a pas accès à /api/mft)
        $fiche['curricula'] = array_values(array_map(fn ($en) => \App\Training\Mft::curriculum($en['curriculum']), $fiche['enrolments']));
        return $this->private($this->json($fiche));
    }

    #[Route('/api/public/{token}/trainee/pdf', methods: 'GET')]
    public function traineePdf(string $token, Request $req, EntityManagerInterface $em, SlotService $slots, TrainingService $training, TraineePdf $pdf): Response
    {
        $link = $this->valid($token, $req, $em, $slots, 'trainee');
        return $this->private($pdf->response($training->publicFiche($link->getDiver(), $req->query->getInt('enrolment') ?: null)));
    }

    /** $kind : 'planning' ou 'trainee' (un lien ne donne accès qu'à ce pour quoi il a été créé). */
    private function valid(string $token, Request $req, EntityManagerInterface $em, SlotService $slots, ?string $kind = null): ShareLink
    {
        $link = $em->getRepository(ShareLink::class)->findOneBy(['token' => $token]);
        $now = new \DateTimeImmutable('now', $slots->timezone());
        if ($link && $kind && ($kind === 'trainee') !== ($link->getDiver() !== null)) $link = null;
        if (!$link || !$link->isValid($now)) {
            throw new NotFoundHttpException('Ce lien de partage n\'existe pas ou n\'est plus valable.');
        }
        if (!$link->hasCode()) return $link;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); // blocage stocké en UTC, comme toutes les dates
        if ($link->isLocked($now)) {
            $wait = max(1, (int) ceil(($link->getLockedUntil()->getTimestamp() - $now->getTimestamp()) / 60));
            throw new TooManyRequestsHttpException($wait * 60, "Trop de codes erronés : réessayez dans $wait min.");
        }
        $code = $req->headers->get('X-Share-Code');
        $ok = $link->checkCode($code, $now);
        $em->flush();
        if (!$ok) {
            if ($link->isLocked($now)) throw new TooManyRequestsHttpException(ShareLink::LOCK_MINUTES * 60, 'Trop de codes erronés : réessayez dans ' . ShareLink::LOCK_MINUTES . ' min.');
            throw new HttpException(401, $code ? 'Code d\'accès incorrect.' : 'Ce planning est protégé par un code d\'accès.');
        }
        return $link;
    }

    private function code(mixed $code): ?string
    {
        if ($code === null || trim((string) $code) === '') return null;
        $code = trim((string) $code);
        if (mb_strlen($code) < ShareLink::CODE_MIN || mb_strlen($code) > 64) throw new BadRequestHttpException('Code d\'accès : ' . ShareLink::CODE_MIN . ' à 64 caractères');
        return $code;
    }

    /** Réponse publique : jamais en cache (navigateur, proxy) ni indexée par les moteurs de recherche. */
    private function private(Response $r): Response
    {
        $r->headers->set('Cache-Control', 'no-store, private');
        $r->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $r->headers->set('Referrer-Policy', 'no-referrer');
        return $r;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function period(Request $req): array
    {
        $from = $this->date($req->query->get('from', 'today'), 'from');
        return [$from, $req->query->get('to') ? $this->date($req->query->get('to'), 'to') : $from->modify('+7 days')];
    }

    public function present(ShareLink $l): array
    {
        return ['id' => $l->getId(), 'token' => $l->getToken(), 'label' => $l->getLabel(), 'createdBy' => $l->getCreatedBy()?->getName(),
            'createdAt' => $l->getCreatedAt()->format(\DATE_ATOM), 'expiresAt' => $l->getExpiresAt()?->format('Y-m-d'), 'hasCode' => $l->hasCode(),
            'openCount' => $l->getOpenCount(), 'lastOpenedAt' => $l->getLastOpenedAt()?->format(\DATE_ATOM), 'diverId' => $l->getDiver()?->getId()];
    }
}
