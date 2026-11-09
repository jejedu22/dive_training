<?php
namespace App\Service;

use App\Entity\{Presence, Section, User};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Construit le planning d'occupation d'une période : pour chaque occurrence de créneau,
 * chaque ligne ouverte et ses sections (plan habituel + exceptions).
 * Utilisé par l'écran Planning, l'export PDF et les liens de partage publics.
 */
final class PlanningService
{
    /** 62 jours max (+2 h de marge pour un changement d'heure). */
    public const MAX_SECONDS = 62 * 86400 + 7200;

    public function __construct(private SlotService $slots, private EntityManagerInterface $em) {}

    public function checkPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > self::MAX_SECONDS) throw new BadRequestHttpException('Période invalide (62 jours max)');
    }

    /**
     * @param ?User $me    pour « mine » (sections encadrées) ; null = vue publique
     * @param bool  $tanks inclure le nombre de bouteilles par section
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to, ?User $me = null, bool $tanks = true): array
    {
        $this->checkPeriod($from, $to);
        $mine = [];
        if ($me) foreach ($this->em->getRepository(Section::class)->findAll() as $s) if ($s->getInstructors()->contains($me)) $mine[] = $s->getId();

        $counts = $tanks ? $this->tankCounts($from, $to) : [];
        $out = [];
        foreach ($this->slots->occurrences($from, $to, true) as $o) {
            $ymd = $o['start']->format('Y-m-d');
            $lanes = [];
            foreach ($o['lanes'] as $lane) {
                $lanes[] = [
                    'laneId' => $lane->getId(), 'lane' => $lane->getName(), 'poolId' => $lane->getPool()->getId(), 'pool' => $lane->getPool()->getName(),
                    // une ligne peut être partagée entre plusieurs sections (liste vide = libre)
                    'sections' => array_map(fn (Section $s) => ['id' => $s->getId(), 'name' => $s->getName(), 'color' => $s->getColor(),
                        'mine' => in_array($s->getId(), $mine, true), 'usesTanks' => $s->usesTanks()], $this->slots->sectionsFor($o, $lane)),
                    'exception' => isset($o['plan']['changed'][$lane->getId()]),
                ];
            }
            $occ = [
                'slotId' => $o['slot']->getId(), 'label' => $o['slot']->getLabel() ?? 'Créneau club', 'date' => $ymd,
                'start' => $o['start']->format(\DATE_ATOM), 'end' => $o['end']->format(\DATE_ATOM),
                'cancelled' => $o['plan']['cancelled'], 'lanes' => $lanes,
                'exceptions' => array_map(fn ($x) => ['id' => $x->getId(), 'label' => $x->getLabel(), 'rule' => $x->describe(), 'cancelled' => $x->isCancelled(), 'punctual' => $x->isPunctual()], $o['plan']['overrides']),
            ];
            if ($tanks) $occ['tanks'] = (object) ($counts[$o['slot']->getId()][$ymd] ?? []);
            $out[] = $occ;
        }
        return ['mySectionIds' => $mine, 'occurrences' => $out];
    }

    /** Bouteilles : [slotId][date][sectionId] => nombre. */
    private function tankCounts(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $tz = $this->slots->timezone();
        $rows = $this->em->createQueryBuilder()->select('IDENTITY(p.slot) AS slot, p.date AS date, IDENTITY(p.section) AS section, COUNT(p.id) AS n')
            ->from(Presence::class, 'p')->where('p.usesTank = true')->andWhere('p.date >= :f')->andWhere('p.date <= :t')
            ->setParameter('f', $from->setTimezone($tz)->format('Y-m-d'))->setParameter('t', $to->setTimezone($tz)->format('Y-m-d'))
            ->groupBy('p.slot, p.date, p.section')->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $r) {
            $d = $r['date'] instanceof \DateTimeInterface ? $r['date']->format('Y-m-d') : substr((string) $r['date'], 0, 10);
            $out[(int) $r['slot']][$d][(int) $r['section']] = (int) $r['n'];
        }
        return $out;
    }
}
