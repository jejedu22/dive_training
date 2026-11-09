<?php
namespace App\Controller;

use App\Entity\Reservation;
use App\Service\{Presenter, ReservationConflictChecker, SlotService};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/reservations')]
class ReservationController extends ApiController
{
    #[Route('', methods: 'GET')]
    public function list(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $qb = $em->getRepository(Reservation::class)->createQueryBuilder('r')->orderBy('r.startAt');
        if ($f = $req->query->get('from')) $qb->andWhere('r.endAt > :f')->setParameter('f', $this->date($f, 'from'));
        if ($t = $req->query->get('to')) $qb->andWhere('r.startAt < :t')->setParameter('t', $this->date($t, 'to'));
        if ($s = $req->query->get('status')) $qb->andWhere('r.status = :s')->setParameter('s', $s);
        return $this->json(array_map(Presenter::reservation(...), $qb->getQuery()->getResult()));
    }

    /** Validation par un référent. Re-vérifie les conflits dans une transaction verrouillée. */
    #[Route('/{id}/confirm', methods: 'POST'), IsGranted('ROLE_REFERENT')]
    public function confirm(Reservation $r, EntityManagerInterface $em, ReservationConflictChecker $checker, SlotService $slots): JsonResponse
    {
        if ($r->getStatus() !== Reservation::PENDING) throw new BadRequestHttpException('Cette demande n\'est plus en attente');
        // Plan effectif du jour : la ligne a pu être attribuée à une autre section par une exception
        $occ = $slots->hasSlots() ? $slots->findOccurrence($r->getStartAt(), $r->getEndAt(), $r->getLane()) : null;
        $owner = $occ ? $slots->allocationFor($occ, $r->getLane()) : null;
        if ($owner && $owner->getId() !== $r->getSession()->getSection()->getId()) {
            throw new ConflictHttpException(sprintf('%s est attribuée à « %s » ce jour-là', $r->getLane()->getName(), $owner->getName()));
        }
        $em->wrapInTransaction(function () use ($r, $em, $checker) {
            $em->lock($r->getLane(), LockMode::PESSIMISTIC_WRITE);
            if ($checker->findConflicts($r->getLane(), $r->getStartAt(), $r->getEndAt(), $r->getId())) {
                throw new ConflictHttpException('Conflit avec une réservation déjà confirmée');
            }
            $r->setStatus(Reservation::CONFIRMED)->setValidatedBy($this->getUser());
        });
        return $this->json(Presenter::reservation($r));
    }

    #[Route('/{id}/reject', methods: 'POST'), IsGranted('ROLE_REFERENT')]
    public function reject(Reservation $r, EntityManagerInterface $em): JsonResponse
    {
        $r->setStatus(Reservation::REJECTED)->setValidatedBy($this->getUser());
        $em->flush();
        return $this->json(Presenter::reservation($r));
    }
}
