<?php
namespace App\Controller;

use App\Entity\{Diver, DiveSession, Lane, Participation, Reservation, Section};
use App\Service\{Presenter, ReservationConflictChecker, SlotService};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, UnprocessableEntityHttpException};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sessions')]
class SessionController extends ApiController
{
    #[Route('', methods: 'GET')]
    public function list(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $qb = $em->getRepository(DiveSession::class)->createQueryBuilder('s')->orderBy('s.startAt');
        if ($f = $req->query->get('from')) $qb->andWhere('s.endAt > :f')->setParameter('f', $this->date($f, 'from'));
        if ($t = $req->query->get('to')) $qb->andWhere('s.startAt < :t')->setParameter('t', $this->date($t, 'to'));
        return $this->json(array_map(fn ($s) => Presenter::session($s, false), $qb->getQuery()->getResult()));
    }

    #[Route('/{id}', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(DiveSession $s): JsonResponse { return $this->json(Presenter::session($s)); }

    /**
     * Crée une séance et une demande de réservation (PENDING) par ligne d'eau.
     * {"sectionId":1,"title":"Palmes","start":"…","end":"…","laneIds":[1,2], "force":false}
     * Si des créneaux club existent :
     *  - chaque ligne doit être ouverte sur un créneau qui contient la séance ;
     *  - une ligne attribuée à une autre section est refusée ;
     *  - une ligne attribuée à la section de la séance est confirmée directement (le plan vaut validation).
     * Un référent peut passer outre ("force": true).
     */
    #[Route('', methods: 'POST')]
    public function create(Request $req, EntityManagerInterface $em, ReservationConflictChecker $checker, SlotService $slots): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'sectionId', 'title', 'start', 'end', 'laneIds');
        $start = $this->date($d['start'], 'start');
        $end = $this->date($d['end'], 'end');
        if ($end <= $start) throw new BadRequestHttpException('La fin doit être après le début');
        if (!is_array($d['laneIds']) || !$d['laneIds']) throw new BadRequestHttpException('Au moins une ligne d\'eau');

        $section = $em->find(Section::class, $d['sectionId']) ?? throw $this->createNotFoundException('Section introuvable');
        $session = (new DiveSession())->setSection($section)->setTitle($d['title'])->setStartAt($start)->setEndAt($end)->setCreatedBy($this->getUser());

        $force = !empty($d['force']) && $this->isGranted('ROLE_REFERENT');
        foreach (array_unique($d['laneIds']) as $laneId) {
            $lane = $em->find(Lane::class, $laneId) ?? throw $this->createNotFoundException("Ligne $laneId introuvable");
            $laneName = sprintf('%s (%s)', $lane->getName(), $lane->getPool()?->getName());
            $status = Reservation::PENDING;
            if ($slots->hasSlots()) {
                $occ = $slots->findOccurrence($start, $end, $lane);
                if (!$occ && !$force) {
                    $list = implode(' ; ', array_map(fn ($s) => $s->describe(), $slots->all()));
                    throw new UnprocessableEntityHttpException(sprintf('%s n\'est pas ouverte au club à cet horaire. Créneaux : %s', $laneName, $list));
                }
                $owner = $occ ? $slots->allocationFor($occ, $lane) : null; // plan effectif de ce jour-là (exceptions comprises)
                if ($owner && $owner->getId() !== $section->getId() && !$force) {
                    throw new ConflictHttpException(sprintf('%s est attribuée à « %s » ce jour-là', $laneName, $owner->getName()));
                }
                if ($owner && $owner->getId() === $section->getId()) $status = Reservation::CONFIRMED;
            }
            if ($checker->findConflicts($lane, $start, $end)) {
                throw new ConflictHttpException(sprintf('%s est déjà réservée sur ce créneau', $laneName));
            }
            $session->addReservation((new Reservation())->setLane($lane)->setStartAt($start)->setEndAt($end)->setRequestedBy($this->getUser())->setStatus($status));
        }
        foreach ($section->getDivers() as $diver) {
            $session->addParticipation((new Participation())->setDiver($diver));
        }
        $em->persist($session);
        $em->flush();
        return $this->json(Presenter::session($session), 201);
    }

    /** Indique si l'élève plonge avec bouteille : {"usesTank":true} */
    #[Route('/{id}/participants/{diverId}', methods: 'PUT')]
    public function setTank(DiveSession $s, int $diverId, Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = $this->body($req);
        $this->required($d, 'usesTank');
        $p = null;
        foreach ($s->getParticipations() as $x) if ($x->getDiver()->getId() === $diverId) $p = $x;
        if (!$p) {
            $diver = $em->find(Diver::class, $diverId) ?? throw $this->createNotFoundException('Élève introuvable');
            $s->addParticipation($p = (new Participation())->setDiver($diver));
        }
        $p->setUsesTank((bool) $d['usesTank']);
        $em->flush();
        return $this->json(Presenter::session($s));
    }

    #[Route('/{id}', methods: 'DELETE')]
    public function delete(DiveSession $s, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($s);
        $em->flush();
        return $this->json(null, 204);
    }
}
