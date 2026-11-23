<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Séance pédagogique d'un stagiaire initiateur (livret pédagogique Initiateur FFESSM) :
 * un module, le niveau et le thème travaillés, et chaque item noté A (acquis), ECA (en cours d'acquisition) ou NT (non travaillé).
 * Complète quand aucun item n'est NT, validée quand tous sont A.
 */
#[ORM\Entity]
class TeachingSession
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'sessions'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Enrolment $enrolment;
    #[ORM\Column(length: 10)]
    private string $module;
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;
    /** Niveau de plongeurs travaillé (N1, PA20…). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $level = null;
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $theme = null;
    /** item => A | ECA | NT */
    #[ORM\Column(type: 'json')]
    private array $items = [];
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $instructor = null;
    #[ORM\Column(length: 140)]
    private string $instructorName = '';
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Enrolment $enrolment, string $module, \DateTimeImmutable $date, User $instructor)
    {
        $this->enrolment = $enrolment;
        $this->module = $module;
        $this->date = $date;
        $this->instructor = $instructor;
        $this->instructorName = $instructor->getName() . ($instructor->getLevel() ? ' (' . $instructor->getLevel() . ($instructor->isTsi() ? '-TSI' : '') . ')' : '');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEnrolment(): Enrolment { return $this->enrolment; }
    public function getModule(): string { return $this->module; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getLevel(): ?string { return $this->level; }
    public function setLevel(?string $l): static { $this->level = $l; return $this; }
    public function getTheme(): ?string { return $this->theme; }
    public function setTheme(?string $t): static { $this->theme = $t; return $this; }
    public function getItems(): array { return $this->items; }
    public function setItems(array $i): static { $this->items = $i; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $c): static { $this->comment = $c; return $this; }
    public function getInstructor(): ?User { return $this->instructor; }
    public function getInstructorName(): string { return $this->instructorName; }
}
