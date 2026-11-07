<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Demande de réservation d'une ligne d'eau, validée par un référent. */
#[ORM\Entity]
#[ORM\Index(columns: ['lane_id', 'start_at', 'end_at'])]
class Reservation
{
    public const PENDING = 'PENDING';
    public const CONFIRMED = 'CONFIRMED';
    public const REJECTED = 'REJECTED';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'reservations'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DiveSession $session = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lane $lane = null;
    #[ORM\Column]
    private \DateTimeImmutable $startAt;
    #[ORM\Column]
    private \DateTimeImmutable $endAt;
    #[ORM\Column(length: 12)]
    private string $status = self::PENDING;
    #[ORM\ManyToOne]
    private ?User $requestedBy = null;
    #[ORM\ManyToOne]
    private ?User $validatedBy = null;

    public function getId(): ?int { return $this->id; }
    public function getSession(): ?DiveSession { return $this->session; }
    public function setSession(DiveSession $s): static { $this->session = $s; return $this; }
    public function getLane(): ?Lane { return $this->lane; }
    public function setLane(Lane $l): static { $this->lane = $l; return $this; }
    public function getStartAt(): \DateTimeImmutable { return $this->startAt; }
    public function setStartAt(\DateTimeImmutable $d): static { $this->startAt = $d; return $this; }
    public function getEndAt(): \DateTimeImmutable { return $this->endAt; }
    public function setEndAt(\DateTimeImmutable $d): static { $this->endAt = $d; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getRequestedBy(): ?User { return $this->requestedBy; }
    public function setRequestedBy(?User $u): static { $this->requestedBy = $u; return $this; }
    public function getValidatedBy(): ?User { return $this->validatedBy; }
    public function setValidatedBy(?User $u): static { $this->validatedBy = $u; return $this; }
}
