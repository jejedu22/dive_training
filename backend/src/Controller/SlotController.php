<?php
namespace App\Controller;

use App\Entity\ClubSlot;
use App\Service\{Presenter, SlotService};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Créneaux horaires du club. Lecture : tous les encadrants. Modification : référents. */
#[Route('/api/slots')]
class SlotController extends ApiController
{
    #[Route('', methods: 'GET')]
    public function list(SlotService $slots): JsonResponse
    {
        return $this->json(array_map(fn ($s) => Presenter::slot($s, $slots), $slots->all()));
    }

    /** Occurrences concrètes entre deux dates : ?from=…&to=… (31 jours max). */
    #[Route('/occurrences', methods: 'GET')]
    public function occurrences(Request $req, SlotService $slots): JsonResponse
    {
        $from = $this->date($req->query->get('from', 'today'), 'from');
        $to = $req->query->get('to') ? $this->date($req->query->get('to'), 'to') : $from->modify('+7 days');
        if ($to->getTimestamp() - $from->getTimestamp() > 31 * 86400) throw new BadRequestHttpException('Période limitée à 31 jours');
        return $this->json(array_map(Presenter::occurrence(...), $slots->occurrences($from, $to)));
    }

    /** {"label":"Entraînement","weekdays":[1,3],"startTime":"20:00","endTime":"22:00","poolId":null,"validFrom":null,"validUntil":null} */
    #[Route('', methods: 'POST'), IsGranted('ROLE_REFERENT')]
    public function create(Request $req, SlotService $slots, EntityManagerInterface $em): JsonResponse
    {
        $slot = $slots->hydrate(new ClubSlot(), $this->body($req));
        $em->persist($slot);
        $em->flush();
        return $this->json(Presenter::slot($slot, $slots), 201);
    }

    #[Route('/{id}', methods: 'PUT', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function update(ClubSlot $slot, Request $req, SlotService $slots, EntityManagerInterface $em): JsonResponse
    {
        $slots->hydrate($slot, $this->body($req));
        $em->flush();
        return $this->json(Presenter::slot($slot, $slots));
    }

    /** Plan d'occupation : {"allocations":[{"laneId":1,"sectionId":3},{"laneId":2,"sectionId":null}]} */
    #[Route('/{id}/allocations', methods: 'PUT', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function allocations(ClubSlot $slot, Request $req, SlotService $slots, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        if (!isset($d['allocations']) || !is_array($d['allocations'])) throw new BadRequestHttpException('Champ requis : allocations');
        $slots->setAllocations($slot, $d['allocations']);
        $em->flush();
        return $this->json(Presenter::slot($slot, $slots));
    }

    #[Route('/{id}', methods: 'DELETE', requirements: ['id' => '\d+']), IsGranted('ROLE_REFERENT')]
    public function delete(ClubSlot $slot, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($slot);
        $em->flush();
        return $this->json(null, 204);
    }
}
