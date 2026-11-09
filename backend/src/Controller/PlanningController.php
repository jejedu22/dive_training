<?php
namespace App\Controller;

use App\Entity\{Presence, Section, User};
use App\Service\SlotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le planning d'occupation : pour chaque créneau du club sur la période, chaque ligne ouverte
 * et la section qui l'occupe ce jour-là (plan habituel + exceptions), avec le nombre de bouteilles
 * par section. « mine » = sections encadrées par l'utilisateur connecté.
 */
class PlanningController extends ApiController
{
    #[Route('/api/planning', methods: 'GET')]
    public function planning(Request $req, SlotService $slots, EntityManagerInterface $em): JsonResponse
    {
        $from = $this->date($req->query->get('from', 'today'), 'from');
        $to = $req->query->get('to') ? $this->date($req->query->get('to'), 'to') : $from->modify('+7 days');
        if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > (62 * 86400 + 7200)) throw new BadRequestHttpException('Période invalide (62 jours max)');

        /** @var User $me */
        $me = $this->getUser();
        $mine = [];
        foreach ($em->getRepository(Section::class)->findAll() as $s) if ($s->getInstructors()->contains($me)) $mine[] = $s->getId();

        // bouteilles : [slotId][date][sectionId] => nombre
        $tanks = [];
        $rows = $em->createQueryBuilder()->select('IDENTITY(p.slot) AS slot, p.date AS date, IDENTITY(p.section) AS section, COUNT(p.id) AS n')
            ->from(Presence::class, 'p')->where('p.usesTank = true')->andWhere('p.date >= :f')->andWhere('p.date <= :t')
            ->setParameter('f', $from->setTimezone($slots->timezone())->format('Y-m-d'))->setParameter('t', $to->setTimezone($slots->timezone())->format('Y-m-d'))
            ->groupBy('p.slot, p.date, p.section')->getQuery()->getArrayResult();
        foreach ($rows as $r) $tanks[(int) $r['slot']][$r['date'] instanceof \DateTimeInterface ? $r['date']->format('Y-m-d') : substr((string) $r['date'], 0, 10)][(int) $r['section']] = (int) $r['n'];

        $out = [];
        foreach ($slots->occurrences($from, $to, true) as $o) {
            $ymd = $o['start']->format('Y-m-d');
            $lanes = [];
            foreach ($o['lanes'] as $lane) {
                $lanes[] = [
                    'laneId' => $lane->getId(), 'lane' => $lane->getName(), 'poolId' => $lane->getPool()->getId(), 'pool' => $lane->getPool()->getName(),
                    // une ligne peut être partagée entre plusieurs sections (liste vide = libre)
                    'sections' => array_map(fn ($s) => ['id' => $s->getId(), 'name' => $s->getName(), 'color' => $s->getColor(), 'mine' => in_array($s->getId(), $mine, true)], $slots->sectionsFor($o, $lane)),
                    'exception' => isset($o['plan']['changed'][$lane->getId()]),
                ];
            }
            $out[] = [
                'slotId' => $o['slot']->getId(), 'label' => $o['slot']->getLabel() ?? 'Créneau club', 'date' => $ymd,
                'start' => $o['start']->format(\DATE_ATOM), 'end' => $o['end']->format(\DATE_ATOM),
                'cancelled' => $o['plan']['cancelled'], 'lanes' => $lanes,
                'exceptions' => array_map(fn ($x) => ['id' => $x->getId(), 'label' => $x->getLabel(), 'rule' => $x->describe(), 'cancelled' => $x->isCancelled()], $o['plan']['overrides']),
                'tanks' => (object) ($tanks[$o['slot']->getId()][$ymd] ?? []),
            ];
        }
        return $this->json(['mySectionIds' => $mine, 'occurrences' => $out]);
    }
}
