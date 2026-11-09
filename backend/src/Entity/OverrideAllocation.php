<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Dans une exception : la ligne est attribuée à la section, ou libérée (section null). */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['override_id', 'lane_id'])]
class OverrideAllocation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'allocations'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SlotOverride $override = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lane $lane = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Section $section = null;

    public function getId(): ?int { return $this->id; }
    public function getOverride(): ?SlotOverride { return $this->override; }
    public function setOverride(SlotOverride $o): static { $this->override = $o; return $this; }
    public function getLane(): ?Lane { return $this->lane; }
    public function setLane(Lane $l): static { $this->lane = $l; return $this; }
    public function getSection(): ?Section { return $this->section; }
    public function setSection(?Section $s): static { $this->section = $s; return $this; }
}
