<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Créneau horaire récurrent attribué au club (ex. lundi et mercredi 20:00–22:00).
 * Heures exprimées dans le fuseau du club (APP_TIMEZONE).
 * Lignes disponibles : la liste $lanes si elle est renseignée, sinon toutes les lignes du bassin $pool, sinon toutes les lignes.
 */
#[ORM\Entity]
class ClubSlot
{
    public const DAYS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;
    /** Jours ISO : 1 = lundi … 7 = dimanche. @var int[] */
    #[ORM\Column(type: Types::JSON)]
    private array $weekdays = [];
    #[ORM\Column(length: 5)]
    private string $startTime = '20:00';
    #[ORM\Column(length: 5)]
    private string $endTime = '22:00';
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Pool $pool = null;
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;
    /** @var Collection<int, Lane> */
    #[ORM\ManyToMany(targetEntity: Lane::class)]
    #[ORM\JoinTable(name: 'club_slot_lane')]
    private Collection $lanes;
    /** @var Collection<int, SlotAllocation> */
    #[ORM\OneToMany(mappedBy: 'slot', targetEntity: SlotAllocation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $allocations;

    /** @var Collection<int, SlotOverride> */
    #[ORM\OneToMany(mappedBy: 'slot', targetEntity: SlotOverride::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $overrides;

    public function __construct()
    {
        $this->lanes = new ArrayCollection();
        $this->allocations = new ArrayCollection();
        $this->overrides = new ArrayCollection();
    }
    /** @return Collection<int, SlotOverride> */
    public function getOverrides(): Collection { return $this->overrides; }
    public function addOverride(SlotOverride $o): static { $this->overrides->add($o); $o->setSlot($this); return $this; }
    public function removeOverride(SlotOverride $o): static { $this->overrides->removeElement($o); return $this; }

    public function getId(): ?int { return $this->id; }
    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): static { $this->label = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }
    /** @return int[] */
    public function getWeekdays(): array { return $this->weekdays; }
    public function setWeekdays(array $d): static
    {
        $d = array_values(array_unique(array_map('intval', $d)));
        sort($d);
        $this->weekdays = $d;
        return $this;
    }
    public function getStartTime(): string { return $this->startTime; }
    public function setStartTime(string $t): static { $this->startTime = $t; return $this; }
    public function getEndTime(): string { return $this->endTime; }
    public function setEndTime(string $t): static { $this->endTime = $t; return $this; }
    public function getPool(): ?Pool { return $this->pool; }
    public function setPool(?Pool $p): static { $this->pool = $p; return $this; }
    public function getValidFrom(): ?\DateTimeImmutable { return $this->validFrom; }
    public function setValidFrom(?\DateTimeImmutable $d): static { $this->validFrom = $d; return $this; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $d): static { $this->validUntil = $d; return $this; }

    /** @return Collection<int, Lane> */
    public function getLanes(): Collection { return $this->lanes; }
    /** @param Lane[] $lanes */
    public function setLanes(array $lanes): static
    {
        $this->lanes->clear();
        foreach ($lanes as $l) $this->lanes->add($l);
        return $this;
    }
    /** @return Collection<int, SlotAllocation> */
    public function getAllocations(): Collection { return $this->allocations; }
    public function addAllocation(SlotAllocation $a): static { $this->allocations->add($a); $a->setSlot($this); return $this; }
    public function removeAllocation(SlotAllocation $a): static { $this->allocations->removeElement($a); return $this; }
    public function allocationFor(Lane $lane): ?SlotAllocation
    {
        foreach ($this->allocations as $a) if ($a->getLane()->getId() === $lane->getId()) return $a;
        return null;
    }

    /** Libellé lisible : « Lundi, mercredi 20:00–22:00 ». */
    public function describe(): string
    {
        $days = implode(', ', array_map(fn (int $d) => self::DAYS[$d] ?? '?', $this->weekdays));
        return ucfirst($days) . ' ' . $this->startTime . '–' . $this->endTime . ($this->pool ? ' (' . $this->pool->getName() . ')' : '');
    }
}
