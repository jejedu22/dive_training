<?php
namespace App\Entity;

use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Doctrine\ORM\Mapping as ORM;

/** Élève plongeur. N'a pas de compte : seuls les encadrants se connectent. */
#[ORM\Entity]
class Diver
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 80)]
    private string $firstName = '';
    #[ORM\Column(length: 80)]
    private string $lastName = '';
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $level = null;
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $licence = null;
    /** Stagiaire archivé : n'apparaît plus dans les listes, son suivi reste consultable. */
    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;
    /** @var Collection<int, Enrolment> */
    #[ORM\OneToMany(targetEntity: Enrolment::class, mappedBy: 'diver', cascade: ['remove'])]
    #[ORM\OrderBy(['startedAt' => 'DESC'])]
    private Collection $enrolments;

    public function __construct() { $this->enrolments = new ArrayCollection(); }

    public function getId(): ?int { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): static { $this->firstName = $v; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): static { $this->lastName = $v; return $this; }
    public function getLevel(): ?string { return $this->level; }
    public function setLevel(?string $v): static { $this->level = $v; return $this; }
    public function getLicence(): ?string { return $this->licence; }
    public function setLicence(?string $v): static { $this->licence = $v; return $this; }
    public function isArchived(): bool { return $this->archived; }
    public function setArchived(bool $b): static { $this->archived = $b; return $this; }
    /** @return Collection<int, Enrolment> */
    public function getEnrolments(): Collection { return $this->enrolments; }
    public function getFullName(): string { return trim($this->firstName . ' ' . $this->lastName); }
}
