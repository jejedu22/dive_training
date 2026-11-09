<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Exception au plan d'occupation habituel d'un créneau, pour certaines dates :
 * - « date » : une date précise ;
 * - « monthly » : le n-ième (1 à 4, ou -1 = dernier) jour de la semaine du mois (ex. 1er lundi).
 * Elle peut annuler le créneau ce jour-là, ou changer l'attribution de certaines lignes
 * (à une section, ou libérée). Les lignes non citées gardent le plan habituel.
 */
#[ORM\Entity]
class SlotOverride
{
    public const DATE = 'date';
    /** Libellé des modifications faites case par case depuis le planning (non affiché sur le planning). */
    public const PUNCTUAL_LABEL = 'Modification ponctuelle';
    public const MONTHLY = 'monthly';
    private const DAYS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'overrides'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ClubSlot $slot = null;
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;
    #[ORM\Column(length: 10)]
    private string $kind = self::DATE;
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $date = null;
    /** Jour ISO (1 = lundi) pour une règle mensuelle. */
    #[ORM\Column(nullable: true)]
    private ?int $weekday = null;
    /** 1 à 4, ou -1 pour « dernier ». */
    #[ORM\Column(nullable: true)]
    private ?int $nth = null;
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;
    /** Créneau annulé ce jour-là (piscine fermée…). */
    #[ORM\Column(options: ['default' => false])]
    private bool $cancelled = false;
    /** @var Collection<int, OverrideAllocation> */
    #[ORM\OneToMany(mappedBy: 'override', targetEntity: OverrideAllocation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $allocations;

    public function __construct() { $this->allocations = new ArrayCollection(); }

    public function getId(): ?int { return $this->id; }
    public function getSlot(): ?ClubSlot { return $this->slot; }
    public function setSlot(ClubSlot $s): static { $this->slot = $s; return $this; }
    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): static { $this->label = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $k): static { $this->kind = $k; return $this; }
    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(?\DateTimeImmutable $d): static { $this->date = $d; return $this; }
    public function getWeekday(): ?int { return $this->weekday; }
    public function setWeekday(?int $w): static { $this->weekday = $w; return $this; }
    public function getNth(): ?int { return $this->nth; }
    public function setNth(?int $n): static { $this->nth = $n; return $this; }
    public function getValidFrom(): ?\DateTimeImmutable { return $this->validFrom; }
    public function setValidFrom(?\DateTimeImmutable $d): static { $this->validFrom = $d; return $this; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $d): static { $this->validUntil = $d; return $this; }
    public function isCancelled(): bool { return $this->cancelled; }
    public function setCancelled(bool $c): static { $this->cancelled = $c; return $this; }
    /** @return Collection<int, OverrideAllocation> */
    public function getAllocations(): Collection { return $this->allocations; }
    public function clearAllocations(): static { $this->allocations->clear(); return $this; }
    public function addAllocation(OverrideAllocation $a): static { $this->allocations->add($a); $a->setOverride($this); return $this; }

    public function isPunctual(): bool { return $this->kind === self::DATE && $this->label === self::PUNCTUAL_LABEL; }

    /** S'applique-t-elle à cette date (AAAA-MM-JJ, jour local du club) ? */
    public function matches(string $ymd): bool
    {
        if ($this->kind === self::DATE) return $this->date?->format('Y-m-d') === $ymd;
        if ($this->validFrom && $ymd < $this->validFrom->format('Y-m-d')) return false;
        if ($this->validUntil && $ymd > $this->validUntil->format('Y-m-d')) return false;
        $d = new \DateTimeImmutable($ymd);
        if ((int) $d->format('N') !== $this->weekday) return false;
        $day = (int) $d->format('j');
        return $this->nth === -1 ? $day + 7 > (int) $d->format('t') : (int) ceil($day / 7) === $this->nth;
    }

    /** « le 1er lundi de chaque mois », « le dernier mercredi de chaque mois », « le lundi 7 décembre 2026 ». */
    public function describe(): string
    {
        if ($this->kind === self::DATE) {
            $d = $this->date;
            $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            return $d ? sprintf('le %s %d %s %s', self::DAYS[(int) $d->format('N')], (int) $d->format('j'), $months[(int) $d->format('n')], $d->format('Y')) : '?';
        }
        $nth = match ($this->nth) { 1 => '1er', -1 => 'dernier', default => $this->nth . 'e' };
        return sprintf('le %s %s de chaque mois', $nth, self::DAYS[$this->weekday] ?? '?');
    }
}
