<?php
namespace App\Service;

use App\Entity\{ClubSlot, Lane, OverrideAllocation, Pool, Section, SlotAllocation, SlotOverride};
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
     * Plan effectif d'un créneau à une date : plan habituel, puis exceptions mensuelles,
     * puis exceptions à date précise (la dernière a le dernier mot).
     * @return array{alloc: array<int, ?Section>, cancelled: bool, overrides: SlotOverride[], changed: array<int, true>}
     */
    public function planFor(ClubSlot $slot, string $ymd, ?SlotOverride $exclude = null): array
    {
        $alloc = [];
        foreach ($slot->getAllocations() as $a) $alloc[$a->getLane()->getId()] = $a->getSection();
        $matching = array_filter($slot->getOverrides()->getValues(), fn (SlotOverride $o) => $o !== $exclude && $o->matches($ymd));
        usort($matching, fn (SlotOverride $a, SlotOverride $b) => [$a->getKind() === SlotOverride::DATE, $a->getId()] <=> [$b->getKind() === SlotOverride::DATE, $b->getId()]);
        $cancelled = false;
        $changed = [];
        foreach ($matching as $o) {
            if ($o->isCancelled()) $cancelled = true;
            foreach ($o->getAllocations() as $a) {
                $alloc[$a->getLane()->getId()] = $a->getSection();
                $changed[$a->getLane()->getId()] = true;
            }
        }
        return ['alloc' => $alloc, 'cancelled' => $cancelled, 'overrides' => array_values($matching), 'changed' => $changed];
    }

    /** Section à qui la ligne est attribuée sur cette occurrence (null = libre). */
    public function allocationFor(array $occurrence, Lane $lane): ?Section
    {
        return $occurrence['plan']['alloc'][$lane->getId()] ?? null;
    }

    /**
     * Occurrences des créneaux qui recoupent [from, to[, avec leur plan effectif.
     * Les occurrences annulées par une exception ne sont renvoyées que si $withCancelled.
     * @return list<array{slot: ClubSlot, start: \DateTimeImmutable, end: \DateTimeImmutable, lanes: Lane[], plan: array}>
     */
    public function occurrences(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $withCancelled = false, ?ClubSlot $only = null): array
    {
        $slots = $only ? [$only] : $this->all();
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
                if (!($start < $to && $end > $from)) continue;
                $plan = $this->planFor($slot, $date);
                if ($plan['cancelled'] && !$withCancelled) continue;
                $out[] = ['slot' => $slot, 'start' => $start, 'end' => $end, 'lanes' => $this->availableLanes($slot), 'plan' => $plan];
            }
        }
        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);
        return $out;
    }

    /**
     * Occurrence (non annulée) qui contient entièrement [start, end] et où la ligne est ouverte.
     */
    public function findOccurrence(\DateTimeImmutable $start, \DateTimeImmutable $end, Lane $lane): ?array
    {
        foreach ($this->occurrences($start->modify('-1 day'), $end->modify('+1 day')) as $o) {
            if ($o['start'] <= $start && $end <= $o['end'] && $this->isLaneAvailable($o['slot'], $lane)) return $o;
        }
        return null;
    }

    /** Valide et applique les données d'une exception reçues de l'API. */
    public function hydrateOverride(SlotOverride $o, array $d): SlotOverride
    {
        $slot = $o->getSlot();
        $kind = $d['kind'] ?? $o->getKind();
        if (!in_array($kind, [SlotOverride::DATE, SlotOverride::MONTHLY], true)) throw new BadRequestHttpException('Type d\'exception invalide (date ou monthly)');
        $o->setKind($kind)->setLabel($d['label'] ?? $o->getLabel())->setCancelled((bool) ($d['cancelled'] ?? $o->isCancelled()));
        $date = fn (string $k) => isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null
            ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d[$k]) ? new \DateTimeImmutable($d[$k]) : throw new BadRequestHttpException("Date invalide : $k (AAAA-MM-JJ)"))
            : null;
        if ($kind === SlotOverride::DATE) {
            $day = $date('date') ?? $o->getDate() ?? throw new BadRequestHttpException('Choisissez la date');
            if (!in_array((int) $day->format('N'), $slot->getWeekdays(), true)) throw new BadRequestHttpException('Ce créneau n\'a pas lieu ce jour-là de la semaine');
            $o->setDate($day)->setWeekday(null)->setNth(null)->setValidFrom(null)->setValidUntil(null);
        } else {
            $weekday = (int) ($d['weekday'] ?? $o->getWeekday());
            $nth = (int) ($d['nth'] ?? $o->getNth());
            if (!in_array($weekday, $slot->getWeekdays(), true)) throw new BadRequestHttpException('Ce créneau n\'a pas lieu ce jour-là de la semaine');
            if (!in_array($nth, [1, 2, 3, 4, -1], true)) throw new BadRequestHttpException('Rang invalide (1er à 4e, ou dernier)');
            $o->setDate(null)->setWeekday($weekday)->setNth($nth);
            if (array_key_exists('validFrom', $d)) $o->setValidFrom($date('validFrom'));
            if (array_key_exists('validUntil', $d)) $o->setValidUntil($date('validUntil'));
        }
        if (array_key_exists('allocations', $d)) {
            if (!is_array($d['allocations'])) throw new BadRequestHttpException('Format : [{"laneId":1,"sectionId":2|null}]');
            $o->clearAllocations();
            $this->em->flush(); // supprime les anciennes lignes avant d'insérer (contrainte d'unicité)
            foreach ($d['allocations'] as $it) {
                $lane = $this->em->find(Lane::class, $it['laneId'] ?? 0) ?? throw new BadRequestHttpException('Ligne introuvable');
                if (!$this->isLaneAvailable($slot, $lane)) throw new BadRequestHttpException(sprintf('%s (%s) n\'est pas ouverte sur ce créneau', $lane->getName(), $lane->getPool()->getName()));
                $section = empty($it['sectionId']) ? null : ($this->em->find(Section::class, $it['sectionId']) ?? throw new BadRequestHttpException('Section introuvable'));
                $o->addAllocation((new OverrideAllocation())->setLane($lane)->setSection($section));
            }
        }
        if (!$o->isCancelled() && $o->getAllocations()->isEmpty()) {
            throw new BadRequestHttpException('Indiquez au moins une ligne à modifier, ou cochez « créneau annulé »');
        }
        return $o;
    }

    /**
     * Modification ponctuelle d'une date : fusionne les choix dans l'exception « date » de ce jour
     * (créée au besoin). Une ligne remise comme dans le plan sans exception date est retirée ;
     * une exception devenue vide est supprimée.
     * @param list<array{laneId:int, sectionId:int|null|string}> $items sectionId : id, null (libre) ou "same" (plan sans modification ponctuelle)
     */
    public function editDate(ClubSlot $slot, string $ymd, array $items, ?bool $cancelled): ?SlotOverride
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) throw new BadRequestHttpException('Date invalide (AAAA-MM-JJ)');
        $day = new \DateTimeImmutable($ymd);
        if (!in_array((int) $day->format('N'), $slot->getWeekdays(), true)) throw new BadRequestHttpException('Ce créneau n\'a pas lieu ce jour-là');
        $o = null;
        foreach ($slot->getOverrides() as $x) if ($x->getKind() === SlotOverride::DATE && $x->getDate()?->format('Y-m-d') === $ymd) $o = $x;
        if (!$o) {
            $o = (new SlotOverride())->setKind(SlotOverride::DATE)->setDate($day)->setLabel('Modification ponctuelle');
            $slot->addOverride($o);
            $this->em->persist($o);
        }
        if ($cancelled !== null) $o->setCancelled($cancelled);
        // plan de ce jour sans cette exception : sert à savoir si un choix est redevenu « normal »
        $without = $this->planFor($slot, $ymd, $o);
        $current = [];
        foreach ($o->getAllocations() as $a) $current[$a->getLane()->getId()] = $a->getSection();
        foreach ($items as $it) {
            $lane = $this->em->find(Lane::class, $it['laneId'] ?? 0) ?? throw new BadRequestHttpException('Ligne introuvable');
            if (!$this->isLaneAvailable($slot, $lane)) throw new BadRequestHttpException(sprintf('%s n\'est pas ouverte sur ce créneau', $lane->getName()));
            $v = $it['sectionId'] ?? null;
            $section = ($v === null || $v === 'same') ? null : ($this->em->find(Section::class, $v) ?? throw new BadRequestHttpException('Section introuvable'));
            $normal = $without['alloc'][$lane->getId()] ?? null;
            if ($v === 'same' || $section?->getId() === $normal?->getId()) unset($current[$lane->getId()]);
            else $current[$lane->getId()] = $section;
        }
        $o->clearAllocations();
        $this->em->flush();
        foreach ($current as $laneId => $section) {
            $o->addAllocation((new OverrideAllocation())->setLane($this->em->find(Lane::class, $laneId))->setSection($section));
        }
        if (!$o->isCancelled() && $o->getAllocations()->isEmpty()) {
            $slot->removeOverride($o);
            $this->em->flush();
            return null;
        }
        $this->em->flush();
        return $o;
    }
}
