<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Section
{
    /** Palette par défaut : couleurs assez foncées pour un texte blanc lisible. */
    public const PALETTE = ['#1f6fb5', '#c0392b', '#1e8449', '#7d3c98', '#d35400', '#0e8a96', '#8d5524', '#c2378f', '#3949ab', '#7a7d0f', '#00796b', '#5d6d7e'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 120)]
    private string $name = '';
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $targetLevel = null;
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $season = null;
    /** Couleur d'identification sur le planning (#rrggbb). */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    private Collection $instructors;
    /** @var Collection<int, Diver> */
    #[ORM\ManyToMany(targetEntity: Diver::class)]
    private Collection $divers;

    public function __construct() { $this->instructors = new ArrayCollection(); $this->divers = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getTargetLevel(): ?string { return $this->targetLevel; }
    public function setTargetLevel(?string $v): static { $this->targetLevel = $v; return $this; }
    public function getSeason(): ?string { return $this->season; }
    public function setSeason(?string $v): static { $this->season = $v; return $this; }
    public function getColor(): string { return $this->color ?? self::PALETTE[($this->id ?? 0) % count(self::PALETTE)]; }
    public function setColor(?string $c): static { $this->color = $c !== null ? strtolower($c) : null; return $this; }
    public function getInstructors(): Collection { return $this->instructors; }
    public function getDivers(): Collection { return $this->divers; }
    public function addInstructor(User $u): static { if (!$this->instructors->contains($u)) $this->instructors->add($u); return $this; }
    public function addDiver(Diver $d): static { if (!$this->divers->contains($d)) $this->divers->add($d); return $this; }
    public function removeDiver(Diver $d): static { $this->divers->removeElement($d); return $this; }
}
