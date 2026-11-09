<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** « Qui fait bouteille » : un élève d'une section, pour un créneau à une date donnée. */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['slot_id', 'date', 'section_id', 'diver_id'])]
class Presence
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ClubSlot $slot = null;
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Section $section = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Diver $diver = null;
    #[ORM\Column]
    private bool $usesTank = false;

    public function getId(): ?int { return $this->id; }
    public function getSlot(): ?ClubSlot { return $this->slot; }
    public function setSlot(ClubSlot $s): static { $this->slot = $s; return $this; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $d): static { $this->date = $d; return $this; }
    public function getSection(): ?Section { return $this->section; }
    public function setSection(Section $s): static { $this->section = $s; return $this; }
    public function getDiver(): ?Diver { return $this->diver; }
    public function setDiver(Diver $d): static { $this->diver = $d; return $this; }
    public function usesTank(): bool { return $this->usesTank; }
    public function setUsesTank(bool $b): static { $this->usesTank = $b; return $this; }
}
