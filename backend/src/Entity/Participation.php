<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Présence d'un élève à une séance, et s'il plonge avec bouteille. */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['session_id', 'diver_id'])]
class Participation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'participations'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DiveSession $session = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Diver $diver = null;
    #[ORM\Column]
    private bool $usesTank = false;

    public function getId(): ?int { return $this->id; }
    public function getSession(): ?DiveSession { return $this->session; }
    public function setSession(DiveSession $s): static { $this->session = $s; return $this; }
    public function getDiver(): ?Diver { return $this->diver; }
    public function setDiver(Diver $d): static { $this->diver = $d; return $this; }
    public function usesTank(): bool { return $this->usesTank; }
    public function setUsesTank(bool $b): static { $this->usesTank = $b; return $this; }
}
