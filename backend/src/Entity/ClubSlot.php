<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Créneau horaire récurrent attribué au club (ex. lundi et mercredi 20:00–22:00).
 * Heures exprimées dans le fuseau du club (APP_TIMEZONE). Bassin null = tous les bassins.
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

    /** Libellé lisible : « Lundi, mercredi 20:00–22:00 ». */
    public function describe(): string
    {
        $days = implode(', ', array_map(fn (int $d) => self::DAYS[$d] ?? '?', $this->weekdays));
        return ucfirst($days) . ' ' . $this->startTime . '–' . $this->endTime . ($this->pool ? ' (' . $this->pool->getName() . ')' : '');
    }
}
