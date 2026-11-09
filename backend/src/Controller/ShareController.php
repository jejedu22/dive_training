<?php
namespace App\Controller;

use App\Entity\ShareLink;
use App\Service\{PlanningPdf, PlanningService, SlotService};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, NotFoundHttpException};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Liens de partage du planning.
 * - Gestion (référents) : /api/share-links
 * - Consultation publique, sans compte : /api/public/{token}, /planning, /pdf (lecture seule, sans élèves ni bouteilles)
 */
class ShareController extends ApiController
{
    #[Route('/api/share-links', methods: 'GET'), IsGranted('ROLE_REFERENT')]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        return $this->json(array_map(fn (ShareLink $l) => $this->present($l), $em->getRepository(ShareLink::class)->findBy([], ['createdAt' => 'DESC'])));
    }

    /** {"label":"Piscine municipale","expiresAt":"2027-06-30"|null} */
    #[Route('/api/share-links', methods: 'POST'), IsGranted('ROLE_REFERENT')]
    public function create(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $label = $d['label'] ?? null;
        if ($label !== null && mb_strlen(trim((string) $label)) > 120) throw new BadRequestHttpException('Nom : 120 caractères maximum');
        $link = (new ShareLink())->setLabel($label)->setCreatedBy($this->getUser());
        if (!empty($d['expiresAt'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['expiresAt'])) throw new BadRequestHttpException('Date d\'expiration invalide (AAAA-MM-JJ)');
            $link->setExpiresAt(new \DateTimeImmutable($d['expiresAt']));
        }
        $em->persist($link);
        $em->flush();
        return $this->json($this->present($link), 201);
    }

    /** Révoque le lien : l'adresse ne fonctionne plus. */
    #[Route('/api/share-links/{id}', methods: 'DELETE', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function delete(ShareLink $link, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($link);
        $em->flush();
        return $this->json(null, 204);
    }

    // ---------- accès public (sans compte)

    #[Route('/api/public/{token}', methods: 'GET')]
    public function info(string $token, EntityManagerInterface $em, SlotService $slots): JsonResponse
    {
        $link = $this->valid($token, $em, $slots);
        return $this->json(['label' => $link->getLabel(), 'expiresAt' => $link->getExpiresAt()?->format('Y-m-d')]);
    }

    #[Route('/api/public/{token}/planning', methods: 'GET')]
    public function planning(string $token, Request $req, EntityManagerInterface $em, SlotService $slots, PlanningService $planning): JsonResponse
    {
        $this->valid($token, $em, $slots);
        [$from, $to] = $this->period($req);
        return $this->json($planning->build($from, $to, null, false));
    }

    #[Route('/api/public/{token}/pdf', methods: 'GET')]
    public function pdf(string $token, Request $req, EntityManagerInterface $em, SlotService $slots, PlanningService $planning, PlanningPdf $pdf): Response
    {
        $this->valid($token, $em, $slots);
        [$from, $to] = $this->period($req);
        return $pdf->response($planning->build($from, $to, null, false), (string) $req->query->get('title', 'Planning des lignes d\'eau'));
    }

    private function valid(string $token, EntityManagerInterface $em, SlotService $slots): ShareLink
    {
        $link = $em->getRepository(ShareLink::class)->findOneBy(['token' => $token]);
        if (!$link || !$link->isValid(new \DateTimeImmutable('now', $slots->timezone()))) {
            throw new NotFoundHttpException('Ce lien de partage n\'existe pas ou n\'est plus valable.');
        }
        return $link;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function period(Request $req): array
    {
        $from = $this->date($req->query->get('from', 'today'), 'from');
        return [$from, $req->query->get('to') ? $this->date($req->query->get('to'), 'to') : $from->modify('+7 days')];
    }

    private function present(ShareLink $l): array
    {
        return ['id' => $l->getId(), 'token' => $l->getToken(), 'label' => $l->getLabel(), 'createdBy' => $l->getCreatedBy()?->getName(),
            'createdAt' => $l->getCreatedAt()->format(\DATE_ATOM), 'expiresAt' => $l->getExpiresAt()?->format('Y-m-d')];
    }
}
