<?php
namespace App\Service;

use App\Entity\{ClubSlot, SlotOverride, Diver, Lane, Pool, Section, User};

/** Transforme les entités en tableaux JSON. */
final class Presenter
{
    private const DATE = \DATE_ATOM;

    /** $self : ajoute les préférences (uniquement pour l'utilisateur connecté). */
    public static function user(User $u, bool $self = false): array
    {
        $data = [
            'id' => $u->getId(), 'email' => $u->getEmail(), 'name' => $u->getName(), 'phone' => $u->getPhone(),
            'avatarColor' => $u->getAvatarColor(),
            'roles' => $u->getStoredRoles(), 'referent' => $u->isReferent(), 'admin' => $u->isAdmin(), 'active' => $u->isActive(),
            'createdAt' => $u->getCreatedAt()?->format(self::DATE), 'lastLoginAt' => $u->getLastLoginAt()?->format(self::DATE),
        ];
        if ($self) $data['preferences'] = $u->getPreferences();
        return $data;
    }

    public static function lane(Lane $l): array
    {
        return ['id' => $l->getId(), 'name' => $l->getName(), 'maxDepth' => $l->getMaxDepth(), 'position' => $l->getPosition(), 'poolId' => $l->getPool()?->getId()];
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
            'overrides' => array_map([self::class, 'override'], $s->getOverrides()->getValues()),
        ];
    }

    /** Occurrence d'un créneau avec son plan effectif (exceptions comprises). */
    public static function occurrence(array $o): array
    {
        $allocations = [];
        foreach ($o['plan']['alloc'] as $laneId => $sections) {
            foreach ($sections as $section) $allocations[] = ['laneId' => $laneId, 'sectionId' => $section->getId(), 'section' => $section->getName(), 'color' => $section->getColor()];
        }
        return [
            'slotId' => $o['slot']->getId(), 'label' => $o['slot']->getLabel() ?? 'Créneau club',
            'poolId' => $o['slot']->getPool()?->getId(), 'pool' => $o['slot']->getPool()?->getName(),
            'start' => $o['start']->format(self::DATE), 'end' => $o['end']->format(self::DATE),
            'laneIds' => array_map(fn (Lane $l) => $l->getId(), $o['lanes']),
            'allocations' => $allocations,
            'cancelled' => $o['plan']['cancelled'],
            'changedLaneIds' => array_keys($o['plan']['changed']),
            'exceptions' => array_map(fn (SlotOverride $x) => ['id' => $x->getId(), 'label' => $x->getLabel(), 'rule' => $x->describe(), 'cancelled' => $x->isCancelled()], $o['plan']['overrides']),
        ];
    }

    public static function override(SlotOverride $o): array
    {
        return [
            'id' => $o->getId(), 'slotId' => $o->getSlot()?->getId(), 'label' => $o->getLabel(), 'kind' => $o->getKind(),
            'date' => $o->getDate()?->format('Y-m-d'), 'weekday' => $o->getWeekday(), 'nth' => $o->getNth(),
            'validFrom' => $o->getValidFrom()?->format('Y-m-d'), 'validUntil' => $o->getValidUntil()?->format('Y-m-d'),
            'cancelled' => $o->isCancelled(), 'rule' => $o->describe(),
            'allocations' => array_map(fn ($a) => [
                'laneId' => $a->getLane()->getId(), 'sectionId' => $a->getSection()?->getId(), 'section' => $a->getSection()?->getName(), 'color' => $a->getSection()?->getColor(),
            ], $o->getAllocations()->getValues()),
        ];
    }
}
