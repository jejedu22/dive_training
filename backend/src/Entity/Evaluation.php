<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Observation datée d'un élément du MFT pour un stagiaire : ECA (en cours d'acquisition), A (acquis) ou V (validé).
 * L'historique est conservé ; le statut d'un élément est celui de sa dernière évaluation.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['enrolment_id', 'skill'])]
class Evaluation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'evaluations'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Enrolment $enrolment;
    /** Clé de l'élément (ex. N1.ventiler.masque). */
    #[ORM\Column(length: 60)]
    private string $skill;
    #[ORM\Column(length: 3)]
    private string $status;
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;
    /** pool | pit | natural */
    #[ORM\Column(length: 10)]
    private string $place;
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $site = null;
    #[ORM\Column(nullable: true)]
    private ?int $depth = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $instructor = null;
    /** Nom et niveau de l'encadrant au moment de l'évaluation (conservés si le compte change ou disparaît). */
    #[ORM\Column(length: 140)]
    private string $instructorName = '';
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Enrolment $enrolment, string $skill, string $status, \DateTimeImmutable $date, string $place, User $instructor)
    {
        $this->enrolment = $enrolment;
        $this->skill = $skill;
        $this->status = $status;
        $this->date = $date;
        $this->place = $place;
        $this->instructor = $instructor;
        $this->instructorName = $instructor->getName() . ($instructor->getLevel() ? ' (' . $instructor->getLevel() . ')' : '');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEnrolment(): Enrolment { return $this->enrolment; }
    public function getSkill(): string { return $this->skill; }
    public function getStatus(): string { return $this->status; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getPlace(): string { return $this->place; }
    public function getSite(): ?string { return $this->site; }
    public function setSite(?string $s): static { $this->site = $s; return $this; }
    public function getDepth(): ?int { return $this->depth; }
    public function setDepth(?int $d): static { $this->depth = $d; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $c): static { $this->comment = $c; return $this; }
    public function getInstructor(): ?User { return $this->instructor; }
    public function getInstructorName(): string { return $this->instructorName; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
