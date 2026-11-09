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
        if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > (31 * 86400 + 7200)) throw new BadRequestHttpException('Période invalide (31 jours max)');

        /** @var User $me */
        $me = $this->getUser();
        $mine = [];
        foreach ($em->getRepository(Section::class)->findAll() as $s) {
            if ($s->getInstructors()->contains($me)) $mine[] = $s->getId();
        }

        $reservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.status NOT IN (:off)')->andWhere('r.endAt > :f')->andWhere('r.startAt < :t')
            ->setParameter('off', [Reservation::REJECTED, Reservation::CANCELLED])->setParameter('f', $from)->setParameter('t', $to)
            ->getQuery()->getResult();

        $out = [];
        foreach ($slots->occurrences($from, $to, true) as $o) {
            $lanes = [];
            foreach ($o['lanes'] as $lane) {
                $owner = $slots->allocationFor($o, $lane); // plan effectif (exceptions comprises)
                $res = array_values(array_filter($reservations, fn (Reservation $r) =>
                    $r->getLane()->getId() === $lane->getId() && $r->getStartAt() < $o['end'] && $r->getEndAt() > $o['start']));
                $lanes[] = [
                    'laneId' => $lane->getId(), 'lane' => $lane->getName(), 'poolId' => $lane->getPool()->getId(), 'pool' => $lane->getPool()->getName(),
                    'allocation' => $owner ? ['sectionId' => $owner->getId(), 'section' => $owner->getName(), 'color' => $owner->getColor(), 'mine' => in_array($owner->getId(), $mine, true)] : null,
                    'exception' => isset($o['plan']['changed'][$lane->getId()]),
                    'reservations' => array_map(fn (Reservation $r) => [
                        'id' => $r->getId(), 'sessionId' => $r->getSession()->getId(), 'title' => $r->getSession()->getTitle(),
                        'sectionId' => $r->getSession()->getSection()->getId(), 'section' => $r->getSession()->getSection()->getName(), 'color' => $r->getSession()->getSection()->getColor(),
                        'status' => $r->getStatus(), 'start' => $r->getStartAt()->format(\DATE_ATOM), 'end' => $r->getEndAt()->format(\DATE_ATOM),
                        'mine' => in_array($r->getSession()->getSection()->getId(), $mine, true),
                    ], $res),
                ];
            }
            $out[] = [
                'slotId' => $o['slot']->getId(), 'label' => $o['slot']->getLabel() ?? 'Créneau club',
                'start' => $o['start']->format(\DATE_ATOM), 'end' => $o['end']->format(\DATE_ATOM), 'lanes' => $lanes,
                'cancelled' => $o['plan']['cancelled'],
                'exceptions' => array_map(fn ($x) => ['id' => $x->getId(), 'label' => $x->getLabel(), 'rule' => $x->describe(), 'cancelled' => $x->isCancelled()], $o['plan']['overrides']),
            ];
        }
        return $this->json(['mySectionIds' => $mine, 'occurrences' => $out]);
    }
}
