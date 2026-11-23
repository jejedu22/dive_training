<?php
namespace App\Entity;

use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Inscription d'un stagiaire dans un cursus du MFT (N1, N2, N3). Un seul suivi par cursus et par stagiaire. */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['diver_id', 'curriculum'])]
class Enrolment
{
    public const STATUSES = ['active', 'done', 'abandoned'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'enrolments'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Diver $diver;
    /** Code du cursus (voir App\Training\Mft). */
    #[ORM\Column(length: 10)]
    private string $curriculum;
    /** Version du MFT au moment de l'inscription. */
    #[ORM\Column(length: 40)]
    private string $version;
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;
    #[ORM\Column(length: 12)]
    private string $status = 'active';
    /** Prérequis cochés : libellé => true. */
    #[ORM\Column(type: 'json')]
    private array $prerequisites = [];
    /** Qualifications délivrées : code => {date, by}. */
    #[ORM\Column(type: 'json')]
    private array $issued = [];
    /** Initiateur : modules validés / dispensés et exigences pratiques — {m1: {validated: {date, by}, exempted: {date, by}, requirements: {clé: {date, by}}}}. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $modules = null;
    /** Initiateur : dossier — {stageInitial: {date, ctr}, exam: {date, place}}. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dossier = null;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    /** @var Collection<int, TeachingSession> */
    #[ORM\OneToMany(targetEntity: TeachingSession::class, mappedBy: 'enrolment', cascade: ['remove'])]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $sessions;
    /** @var Collection<int, Evaluation> */
    #[ORM\OneToMany(targetEntity: Evaluation::class, mappedBy: 'enrolment', cascade: ['remove'])]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $evaluations;

    public function __construct(Diver $diver, string $curriculum, string $version, \DateTimeImmutable $startedAt)
    {
        $this->diver = $diver;
        $this->curriculum = $curriculum;
        $this->version = $version;
        $this->startedAt = $startedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->evaluations = new ArrayCollection();
        $this->sessions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getDiver(): Diver { return $this->diver; }
    public function getCurriculum(): string { return $this->curriculum; }
    public function getVersion(): string { return $this->version; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(\DateTimeImmutable $d): static { $this->startedAt = $d; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getPrerequisites(): array { return $this->prerequisites; }
    public function setPrerequisites(array $p): static { $this->prerequisites = $p; return $this; }
    public function getIssued(): array { return $this->issued; }
    public function setIssued(array $i): static { $this->issued = $i; return $this; }
    /** @return Collection<int, Evaluation> */
    public function getEvaluations(): Collection { return $this->evaluations; }
    /** @return Collection<int, TeachingSession> */
    public function getSessions(): Collection { return $this->sessions; }
    public function getModules(): array { return $this->modules ?? []; }
    public function setModules(array $m): static { $this->modules = $m; return $this; }
    public function getDossier(): array { return $this->dossier ?? []; }
    public function setDossier(array $d): static { $this->dossier = $d; return $this; }
}
