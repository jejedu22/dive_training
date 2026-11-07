<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Plan d'occupation récurrent : sur un créneau du club, telle ligne d'eau est attribuée à telle section
 * (ex. lundi 20h–22h : Fosse/Ligne 1 → Niveau 1). S'applique à chaque occurrence du créneau.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['slot_id', 'lane_id'])]
class SlotAllocation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'allocations'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ClubSlot $slot = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lane $lane = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Section $section = null;

    public function getId(): ?int { return $this->id; }
    public function getSlot(): ?ClubSlot { return $this->slot; }
    public function setSlot(ClubSlot $s): static { $this->slot = $s; return $this; }
    public function getLane(): ?Lane { return $this->lane; }
    public function setLane(Lane $l): static { $this->lane = $l; return $this; }
    public function getSection(): ?Section { return $this->section; }
    public function setSection(Section $s): static { $this->section = $s; return $this; }
}
