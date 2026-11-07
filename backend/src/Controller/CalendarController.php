<?php
namespace App\Controller;

use App\Entity\{Pool, Reservation};
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

class CalendarController extends ApiController
{
    /** Ressources (bassins → lignes) et événements pour un calendrier en vue timeline. */
    #[Route('/api/calendar', methods: 'GET')]
    public function calendar(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $qb = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.status != :rej')->setParameter('rej', Reservation::REJECTED)->orderBy('r.startAt');
        if ($f = $req->query->get('from')) $qb->andWhere('r.endAt > :f')->setParameter('f', $this->date($f, 'from'));
        if ($t = $req->query->get('to')) $qb->andWhere('r.startAt < :t')->setParameter('t', $this->date($t, 'to'));

        $resources = [];
        foreach ($em->getRepository(Pool::class)->findBy([], ['name' => 'ASC']) as $pool) {
            foreach ($pool->getLanes() as $lane) {
                $resources[] = ['id' => (string) $lane->getId(), 'title' => $lane->getName(), 'pool' => $pool->getName()];
            }
        }
        $events = array_map(function (Reservation $r) {
            $x = Presenter::reservation($r);
            return [
                'id' => (string) $r->getId(), 'resourceId' => (string) $r->getLane()->getId(),
                'title' => $x['title'] . ' — ' . $x['section'], 'start' => $x['start'], 'end' => $x['end'],
                'status' => $r->getStatus(), 'sessionId' => $x['sessionId'],
            ];
        }, $qb->getQuery()->getResult());

        return $this->json(['resources' => $resources, 'events' => $events]);
    }
}
