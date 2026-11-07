<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Séance de formation d'une section. */
#[ORM\Entity]
class DiveSession
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Section $section = null;
    #[ORM\Column(length: 160)]
    private string $title = '';
    #[ORM\Column]
    private \DateTimeImmutable $startAt;
    #[ORM\Column]
    private \DateTimeImmutable $endAt;
    #[ORM\ManyToOne]
    private ?User $createdBy = null;
    /** @var Collection<int, Participation> */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: Participation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $participations;
    /** @var Collection<int, Reservation> */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: Reservation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $reservations;

    public function __construct() { $this->participations = new ArrayCollection(); $this->reservations = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function getSection(): ?Section { return $this->section; }
    public function setSection(Section $s): static { $this->section = $s; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getStartAt(): \DateTimeImmutable { return $this->startAt; }
    public function setStartAt(\DateTimeImmutable $d): static { $this->startAt = $d; return $this; }
    public function getEndAt(): \DateTimeImmutable { return $this->endAt; }
    public function setEndAt(\DateTimeImmutable $d): static { $this->endAt = $d; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): static { $this->createdBy = $u; return $this; }
    public function getParticipations(): Collection { return $this->participations; }
    public function getReservations(): Collection { return $this->reservations; }
    public function addParticipation(Participation $p): static { $this->participations->add($p); $p->setSession($this); return $this; }
    public function addReservation(Reservation $r): static { $this->reservations->add($r); $r->setSession($this); return $this; }
}
