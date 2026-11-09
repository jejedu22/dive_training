<?php
namespace App\Controller;

use App\Entity\{ClubSlot, SlotOverride};
use App\Service\{Presenter, SlotService};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Exceptions au plan d'occupation d'un créneau (référents).
 * {"label":"Apnée en fosse","kind":"monthly","weekday":1,"nth":1,"cancelled":false,
 *  "allocations":[{"laneId":1,"sectionId":5},{"laneId":2,"sectionId":null}]}
 * La réponse indique combien de réservations à venir ont été annulées ("cancelledReservations").
 */
#[IsGranted('ROLE_REFERENT')]
class OverrideController extends ApiController
{
    #[Route('/api/slots/{id}/overrides', methods: 'POST', requirements: ['id' => '\d+'])]
    public function create(ClubSlot $slot, Request $req, SlotService $slots, EntityManagerInterface $em): JsonResponse
    {
        $o = new SlotOverride();
        $slot->addOverride($o);
        $slots->hydrateOverride($o, $this->body($req));
        $em->persist($o);
        $em->flush();
        return $this->json(Presenter::override($o) + ['cancelledReservations' => $slots->applyOverride($o)], 201);
    }

    #[Route('/api/overrides/{id}', methods: 'PUT', requirements: ['id' => '\d+'])]
    public function update(SlotOverride $o, Request $req, SlotService $slots, EntityManagerInterface $em): JsonResponse
    {
        $slots->hydrateOverride($o, $this->body($req));
        $em->flush();
        return $this->json(Presenter::override($o) + ['cancelledReservations' => $slots->applyOverride($o)]);
    }

    /** Supprimer une exception rétablit le plan habituel (les séances déjà annulées le restent). */
    #[Route('/api/overrides/{id}', methods: 'DELETE', requirements: ['id' => '\d+'])]
    public function delete(SlotOverride $o, EntityManagerInterface $em): JsonResponse
    {
        $o->getSlot()->removeOverride($o);
        $em->flush();
        return $this->json(null, 204);
    }
}
