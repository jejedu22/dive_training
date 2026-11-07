<?php
namespace App\Service;

use App\Entity\Lane;
use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

final class ReservationConflictChecker
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Réservations CONFIRMÉES de la ligne qui chevauchent [start, end[.
     * Deux intervalles se chevauchent si start < autre.end ET end > autre.start.
     *
     * @return Reservation[]
     */
    public function findConflicts(Lane $lane, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $excludeId = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('r')->from(Reservation::class, 'r')
            ->where('r.lane = :lane')->andWhere('r.status = :st')
            ->andWhere('r.startAt < :end')->andWhere('r.endAt > :start')
            ->setParameter('lane', $lane)->setParameter('st', Reservation::CONFIRMED)
            ->setParameter('start', $start)->setParameter('end', $end);
        if ($excludeId !== null) {
            $qb->andWhere('r.id != :id')->setParameter('id', $excludeId);
        }
        return $qb->getQuery()->getResult();
    }
}
