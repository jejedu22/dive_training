<?php
namespace App\Controller;

use App\Entity\{Reservation, Section, User};
use App\Service\SlotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Occupation des lignes d'eau, créneau par créneau : pour chaque ligne ouverte,
 * la section à qui elle est attribuée et les séances réservées. « mine » = sections de l'encadrant connecté.
 */
class OccupancyController extends ApiController
{
    #[Route('/api/occupancy', methods: 'GET')]
    public function occupancy(Request $req, SlotService $slots, EntityManagerInterface $em): JsonResponse
    {
        $from = $this->date($req->query->get('from', 'today'), 'from');
        $to = $req->query->get('to') ? $this->date($req->query->get('to'), 'to') : $from->modify('+7 days');
        if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > 31 * 86400) throw new BadRequestHttpException('Période invalide (31 jours max)');

        /** @var User $me */
        $me = $this->getUser();
        $mine = [];
        foreach ($em->getRepository(Section::class)->findAll() as $s) {
            if ($s->getInstructors()->contains($me)) $mine[] = $s->getId();
        }

        $reservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.status != :rej')->andWhere('r.endAt > :f')->andWhere('r.startAt < :t')
            ->setParameter('rej', Reservation::REJECTED)->setParameter('f', $from)->setParameter('t', $to)
            ->getQuery()->getResult();

        $out = [];
        foreach ($slots->occurrences($from, $to) as $o) {
            $lanes = [];
            foreach ($o['lanes'] as $lane) {
                $alloc = $o['slot']->allocationFor($lane);
                $res = array_values(array_filter($reservations, fn (Reservation $r) =>
                    $r->getLane()->getId() === $lane->getId() && $r->getStartAt() < $o['end'] && $r->getEndAt() > $o['start']));
                $lanes[] = [
                    'laneId' => $lane->getId(), 'lane' => $lane->getName(), 'poolId' => $lane->getPool()->getId(), 'pool' => $lane->getPool()->getName(),
                    'allocation' => $alloc ? ['sectionId' => $alloc->getSection()->getId(), 'section' => $alloc->getSection()->getName(), 'mine' => in_array($alloc->getSection()->getId(), $mine, true)] : null,
                    'reservations' => array_map(fn (Reservation $r) => [
                        'id' => $r->getId(), 'sessionId' => $r->getSession()->getId(), 'title' => $r->getSession()->getTitle(),
                        'sectionId' => $r->getSession()->getSection()->getId(), 'section' => $r->getSession()->getSection()->getName(),
                        'status' => $r->getStatus(), 'start' => $r->getStartAt()->format(\DATE_ATOM), 'end' => $r->getEndAt()->format(\DATE_ATOM),
                        'mine' => in_array($r->getSession()->getSection()->getId(), $mine, true),
                    ], $res),
                ];
            }
            $out[] = [
                'slotId' => $o['slot']->getId(), 'label' => $o['slot']->getLabel() ?? 'Créneau club',
                'start' => $o['start']->format(\DATE_ATOM), 'end' => $o['end']->format(\DATE_ATOM), 'lanes' => $lanes,
            ];
        }
        return $this->json(['mySectionIds' => $mine, 'occurrences' => $out]);
    }
}
