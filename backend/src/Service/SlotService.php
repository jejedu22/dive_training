<?php
namespace App\Service;

use App\Entity\{ClubSlot, Lane, Pool, Section, SlotAllocation};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Créneaux du club : occurrences concrètes, lignes disponibles, plan d'occupation. */
final class SlotService
{
    private \DateTimeZone $tz;
    /** @var array<int, Lane[]> */
    private array $laneCache = [];

    public function __construct(private EntityManagerInterface $em, #[Autowire('%env(APP_TIMEZONE)%')] string $timezone)
    {
        $this->tz = new \DateTimeZone($timezone);
    }

    public function timezone(): \DateTimeZone { return $this->tz; }

    /** @return ClubSlot[] */
    public function all(): array
    {
        return $this->em->getRepository(ClubSlot::class)->findBy([], ['startTime' => 'ASC', 'id' => 'ASC']);
    }

    public function hasSlots(): bool
    {
        return $this->em->getRepository(ClubSlot::class)->count([]) > 0;
    }

    /** Toutes les lignes, triées par bassin puis dans l'ordre du bassin. @return Lane[] */
    public function allLanes(): array
    {
        $lanes = $this->em->getRepository(Lane::class)->findAll();
        usort($lanes, fn (Lane $a, Lane $b) => [$a->getPool()->getName(), $a->getPosition(), $a->getId()] <=> [$b->getPool()->getName(), $b->getPosition(), $b->getId()]);
        return $lanes;
    }

    /** Lignes ouvertes au club sur ce créneau. @return Lane[] */
    public function availableLanes(ClubSlot $slot): array
    {
        if ($slot->getId() !== null && isset($this->laneCache[$slot->getId()])) return $this->laneCache[$slot->getId()];
        if (!$slot->getLanes()->isEmpty()) {
            $ids = array_map(fn (Lane $l) => $l->getId(), $slot->getLanes()->toArray());
            $lanes = array_values(array_filter($this->allLanes(), fn (Lane $l) => in_array($l->getId(), $ids, true)));
        } elseif ($slot->getPool()) {
            $lanes = array_values(array_filter($this->allLanes(), fn (Lane $l) => $l->getPool()->getId() === $slot->getPool()->getId()));
        } else {
            $lanes = $this->allLanes();
        }
        if ($slot->getId() !== null) $this->laneCache[$slot->getId()] = $lanes;
        return $lanes;
    }

    public function isLaneAvailable(ClubSlot $slot, Lane $lane): bool
    {
        foreach ($this->availableLanes($slot) as $l) if ($l->getId() === $lane->getId()) return true;
        return false;
    }

    /** Valide et applique les données reçues de l'API. */
    public function hydrate(ClubSlot $slot, array $d): ClubSlot
    {
        $days = $d['weekdays'] ?? $slot->getWeekdays();
        if (!is_array($days) || !$days || array_diff(array_map('intval', $days), range(1, 7))) {
            throw new BadRequestHttpException('Choisissez au moins un jour (1 = lundi … 7 = dimanche)');
        }
        $start = $d['startTime'] ?? $slot->getStartTime();
        $end = $d['endTime'] ?? $slot->getEndTime();
        foreach ([$start, $end] as $t) {
            if (!is_string($t) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) throw new BadRequestHttpException("Heure invalide : $t (format HH:MM)");
        }
        if ($end <= $start) throw new BadRequestHttpException('L\'heure de fin doit être après l\'heure de début');

        $slot->setWeekdays($days)->setStartTime($start)->setEndTime($end);
        if (array_key_exists('label', $d)) $slot->setLabel($d['label']);
        if (array_key_exists('poolId', $d)) {
            $slot->setPool($d['poolId'] ? ($this->em->find(Pool::class, $d['poolId']) ?? throw new BadRequestHttpException('Bassin introuvable')) : null);
        }
        if (array_key_exists('laneIds', $d)) {
            if (!is_array($d['laneIds'])) throw new BadRequestHttpException('laneIds doit être une liste');
            $lanes = [];
            foreach (array_unique($d['laneIds']) as $id) {
                $lanes[] = $this->em->find(Lane::class, $id) ?? throw new BadRequestHttpException("Ligne $id introuvable");
            }
            $slot->setLanes($lanes);
        }
        foreach (['validFrom' => 'setValidFrom', 'validUntil' => 'setValidUntil'] as $k => $setter) {
            if (!array_key_exists($k, $d)) continue;
            $v = $d[$k] ?: null;
            if ($v !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) throw new BadRequestHttpException("Date invalide : $k (AAAA-MM-JJ)");
            $slot->$setter($v ? new \DateTimeImmutable($v) : null);
        }
        if ($slot->getValidFrom() && $slot->getValidUntil() && $slot->getValidUntil() < $slot->getValidFrom()) {
            throw new BadRequestHttpException('La date de fin de validité précède la date de début');
        }
        // Une ligne qui n'est plus ouverte perd son attribution
        if ($slot->getId() !== null) unset($this->laneCache[$slot->getId()]);
        foreach ($slot->getAllocations()->toArray() as $a) {
            if (!$this->isLaneAvailable($slot, $a->getLane())) $slot->removeAllocation($a);
        }
        return $slot;
    }

    /**
     * Remplace le plan d'occupation du créneau.
     * @param list<array{laneId:int, sectionId:int|null}> $items
     */
    public function setAllocations(ClubSlot $slot, array $items): void
    {
        $wanted = [];
        foreach ($items as $it) {
            if (!is_array($it) || !isset($it['laneId'])) throw new BadRequestHttpException('Format : [{"laneId":1,"sectionId":2}]');
            if (empty($it['sectionId'])) continue;
            $lane = $this->em->find(Lane::class, $it['laneId']) ?? throw new BadRequestHttpException("Ligne {$it['laneId']} introuvable");
            if (!$this->isLaneAvailable($slot, $lane)) {
                throw new BadRequestHttpException(sprintf('%s (%s) n\'est pas ouverte sur ce créneau', $lane->getName(), $lane->getPool()->getName()));
            }
            $wanted[$lane->getId()] = [$lane, $this->em->find(Section::class, $it['sectionId']) ?? throw new BadRequestHttpException('Section introuvable')];
        }
        foreach ($slot->getAllocations()->toArray() as $a) {
            $laneId = $a->getLane()->getId();
            if (!isset($wanted[$laneId])) { $slot->removeAllocation($a); continue; }
            $a->setSection($wanted[$laneId][1]);
            unset($wanted[$laneId]);
        }
        foreach ($wanted as [$lane, $section]) {
            $slot->addAllocation((new SlotAllocation())->setLane($lane)->setSection($section));
        }
    }

    /**
     * Occurrences des créneaux qui recoupent [from, to[.
     * @return list<array{slot: ClubSlot, start: \DateTimeImmutable, end: \DateTimeImmutable, lanes: Lane[]}>
     */
    public function occurrences(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $slots = $this->all();
        if (!$slots || $to <= $from) return [];
        $out = [];
        $day = $from->setTimezone($this->tz)->setTime(0, 0);
        $last = $to->setTimezone($this->tz);
        for ($i = 0; $day < $last && $i < 400; $i++, $day = $day->modify('+1 day')) {
            $iso = (int) $day->format('N');
            $date = $day->format('Y-m-d');
            foreach ($slots as $slot) {
                if (!in_array($iso, $slot->getWeekdays(), true)) continue;
                if ($slot->getValidFrom() && $date < $slot->getValidFrom()->format('Y-m-d')) continue;
                if ($slot->getValidUntil() && $date > $slot->getValidUntil()->format('Y-m-d')) continue;
                $start = new \DateTimeImmutable("$date {$slot->getStartTime()}", $this->tz);
                $end = new \DateTimeImmutable("$date {$slot->getEndTime()}", $this->tz);
                if ($start < $to && $end > $from) $out[] = ['slot' => $slot, 'start' => $start, 'end' => $end, 'lanes' => $this->availableLanes($slot)];
            }
        }
        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);
        return $out;
    }

    /**
     * Occurrence de créneau qui contient entièrement [start, end] et où la ligne est ouverte.
     * @return array{slot: ClubSlot, start: \DateTimeImmutable, end: \DateTimeImmutable, lanes: Lane[]}|null
     */
    public function findOccurrence(\DateTimeImmutable $start, \DateTimeImmutable $end, Lane $lane): ?array
    {
        foreach ($this->occurrences($start->modify('-1 day'), $end->modify('+1 day')) as $o) {
            if ($o['start'] <= $start && $end <= $o['end'] && $this->isLaneAvailable($o['slot'], $lane)) return $o;
        }
        return null;
    }
}
