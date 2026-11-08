<?php
namespace App\Service;

use App\Entity\{ClubSlot, Diver, DiveSession, Lane, Participation, Pool, Reservation, Section, User};

/** Transforme les entités en tableaux JSON. */
final class Presenter
{
    private const DATE = \DATE_ATOM;

    public static function user(User $u): array
    {
        return [
            'id' => $u->getId(), 'email' => $u->getEmail(), 'name' => $u->getName(),
            'roles' => $u->getStoredRoles(), 'referent' => $u->isReferent(), 'admin' => $u->isAdmin(), 'active' => $u->isActive(),
        ];
    }

    public static function lane(Lane $l): array
    {
        return ['id' => $l->getId(), 'name' => $l->getName(), 'maxDepth' => $l->getMaxDepth(), 'poolId' => $l->getPool()?->getId()];
    }

    public static function pool(Pool $p): array
    {
        return ['id' => $p->getId(), 'name' => $p->getName(), 'lanes' => array_map([self::class, 'lane'], $p->getLanes()->getValues())];
    }

    public static function diver(Diver $d): array
    {
        return ['id' => $d->getId(), 'firstName' => $d->getFirstName(), 'lastName' => $d->getLastName(), 'level' => $d->getLevel()];
    }

    public static function section(Section $s): array
    {
        return [
            'id' => $s->getId(), 'name' => $s->getName(), 'targetLevel' => $s->getTargetLevel(), 'season' => $s->getSeason(), 'color' => $s->getColor(),
            'instructors' => array_map([self::class, 'user'], $s->getInstructors()->getValues()),
            'divers' => array_map([self::class, 'diver'], $s->getDivers()->getValues()),
        ];
    }

    public static function reservation(Reservation $r): array
    {
        return [
            'id' => $r->getId(), 'sessionId' => $r->getSession()?->getId(), 'title' => $r->getSession()?->getTitle(),
            'section' => $r->getSession()?->getSection()?->getName(), 'color' => $r->getSession()?->getSection()?->getColor(),
            'lane' => self::lane($r->getLane()), 'pool' => $r->getLane()->getPool()?->getName(),
            'start' => $r->getStartAt()->format(self::DATE), 'end' => $r->getEndAt()->format(self::DATE),
            'status' => $r->getStatus(), 'requestedBy' => $r->getRequestedBy()?->getName(), 'validatedBy' => $r->getValidatedBy()?->getName(),
            // confirmée sans validation manuelle = ligne attribuée à la section dans le plan d'occupation
            'planned' => $r->getStatus() === Reservation::CONFIRMED && $r->getValidatedBy() === null,
        ];
    }

    public static function participation(Participation $p): array
    {
        return ['diver' => self::diver($p->getDiver()), 'usesTank' => $p->usesTank()];
    }

    public static function session(DiveSession $s, bool $detail = true): array
    {
        $data = [
            'id' => $s->getId(), 'title' => $s->getTitle(), 'sectionId' => $s->getSection()?->getId(), 'section' => $s->getSection()?->getName(), 'color' => $s->getSection()?->getColor(),
            'start' => $s->getStartAt()->format(self::DATE), 'end' => $s->getEndAt()->format(self::DATE),
            'reservations' => array_map([self::class, 'reservation'], $s->getReservations()->getValues()),
        ];
        if ($detail) {
            $data['participants'] = array_map([self::class, 'participation'], $s->getParticipations()->getValues());
            $data['tankCount'] = count(array_filter($s->getParticipations()->toArray(), fn (Participation $p) => $p->usesTank()));
        }
        return $data;
    }

    public static function slot(ClubSlot $s, SlotService $slots): array
    {
        $lanes = $slots->availableLanes($s);
        return [
            'id' => $s->getId(), 'label' => $s->getLabel(), 'weekdays' => $s->getWeekdays(),
            'startTime' => $s->getStartTime(), 'endTime' => $s->getEndTime(),
            'poolId' => $s->getPool()?->getId(), 'pool' => $s->getPool()?->getName(),
            'validFrom' => $s->getValidFrom()?->format('Y-m-d'), 'validUntil' => $s->getValidUntil()?->format('Y-m-d'),
            'description' => $s->describe(),
            // Lignes cochées explicitement (vide = tout le bassin / toutes les lignes) et lignes effectivement ouvertes
            'laneIds' => array_map(fn (Lane $l) => $l->getId(), $s->getLanes()->getValues()),
            'availableLaneIds' => array_map(fn (Lane $l) => $l->getId(), $lanes),
            'allocations' => array_map(fn ($a) => [
                'laneId' => $a->getLane()->getId(), 'sectionId' => $a->getSection()->getId(), 'section' => $a->getSection()->getName(), 'color' => $a->getSection()->getColor(),
            ], $s->getAllocations()->getValues()),
        ];
    }

    /** @param array{slot: ClubSlot, start: \DateTimeImmutable, end: \DateTimeImmutable, lanes: Lane[]} $o */
    public static function occurrence(array $o): array
    {
        return [
            'slotId' => $o['slot']->getId(), 'label' => $o['slot']->getLabel() ?? 'Créneau club',
            'poolId' => $o['slot']->getPool()?->getId(), 'pool' => $o['slot']->getPool()?->getName(),
            'start' => $o['start']->format(self::DATE), 'end' => $o['end']->format(self::DATE),
            'laneIds' => array_map(fn (Lane $l) => $l->getId(), $o['lanes']),
            'allocations' => array_map(fn ($a) => ['laneId' => $a->getLane()->getId(), 'sectionId' => $a->getSection()->getId(), 'section' => $a->getSection()->getName(), 'color' => $a->getSection()->getColor()], $o['slot']->getAllocations()->getValues()),
        ];
    }
}
