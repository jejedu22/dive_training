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
        return $this->json(Presenter::override($o), 201);
    }

    #[Route('/api/overrides/{id}', methods: 'PUT', requirements: ['id' => '\d+'])]
    public function update(SlotOverride $o, Request $req, SlotService $slots, EntityManagerInterface $em): JsonResponse
    {
        $slots->hydrateOverride($o, $this->body($req));
        $em->flush();
        return $this->json(Presenter::override($o));
    }

    /**
     * Modification ponctuelle depuis le planning : une ou plusieurs lignes à une date.
     * {"allocations":[{"laneId":1,"sectionId":3}, {"laneId":2,"sectionId":null}, {"laneId":4,"sectionId":"same"}], "cancelled":false}
     * sectionId : section, null = ligne libre, "same" = revenir au plan (règles comprises).
     */
    #[Route('/api/slots/{id}/dates/{date}', methods: 'PUT', requirements: ['id' => '\d+'])]
    public function editDate(ClubSlot $slot, string $date, Request $req, SlotService $slots): JsonResponse
    {
        $d = $this->body($req);
        $o = $slots->editDate($slot, $date, is_array($d['allocations'] ?? null) ? $d['allocations'] : [], isset($d['cancelled']) ? (bool) $d['cancelled'] : null);
        return $this->json($o ? Presenter::override($o) : null);
    }

    /** Supprimer une exception rétablit le plan habituel. */
    #[Route('/api/overrides/{id}', methods: 'DELETE', requirements: ['id' => '\d+'])]
    public function delete(SlotOverride $o, EntityManagerInterface $em): JsonResponse
    {
        $o->getSlot()->removeOverride($o);
        $em->flush();
        return $this->json(null, 204);
    }
}
