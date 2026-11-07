<?php
namespace App\Service;

use App\Entity\{ClubSlot, Pool};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Calcule les occurrences concrètes des créneaux du club et vérifie qu'une séance s'y inscrit. */
final class SlotService
{
    private \DateTimeZone $tz;

    public function __construct(private EntityManagerInterface $em, #[Autowire('%env(APP_TIMEZONE)%')] string $timezone)
    {
        $this->tz = new \DateTimeZone($timezone);
    }

    public function timezone(): \DateTimeZone { return $this->tz; }

    /** @return ClubSlot[] */
    public function all(): array
    {
        return $this->em->getRepository(ClubSlot::class)->findBy([], ['id' => 'ASC']);
    }

    public function hasSlots(): bool
    {
        return $this->em->getRepository(ClubSlot::class)->count([]) > 0;
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
        foreach (['validFrom' => 'setValidFrom', 'validUntil' => 'setValidUntil'] as $k => $setter) {
            if (!array_key_exists($k, $d)) continue;
            $v = $d[$k] ?: null;
            if ($v !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) throw new BadRequestHttpException("Date invalide : $k (AAAA-MM-JJ)");
            $slot->$setter($v ? new \DateTimeImmutable($v) : null);
        }
        if ($slot->getValidFrom() && $slot->getValidUntil() && $slot->getValidUntil() < $slot->getValidFrom()) {
            throw new BadRequestHttpException('La date de fin de validité précède la date de début');
        }
        return $slot;
    }

    /**
     * Occurrences des créneaux qui recoupent [from, to[.
     * @return list<array{slot: ClubSlot, start: \DateTimeImmutable, end: \DateTimeImmutable}>
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
                if ($start < $to && $end > $from) $out[] = ['slot' => $slot, 'start' => $start, 'end' => $end];
            }
        }
        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);
        return $out;
    }

    /** La séance [start, end] tient-elle entièrement dans un créneau valable pour ce bassin ? */
    public function fits(\DateTimeImmutable $start, \DateTimeImmutable $end, Pool $pool): bool
    {
        foreach ($this->occurrences($start->modify('-1 day'), $end->modify('+1 day')) as $o) {
            $slotPool = $o['slot']->getPool();
            if ($slotPool !== null && $slotPool->getId() !== $pool->getId()) continue;
            if ($o['start'] <= $start && $end <= $o['end']) return true;
        }
        return false;
    }
}
