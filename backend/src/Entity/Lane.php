<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Ligne d'eau d'un bassin : unité réservable. */
#[ORM\Entity]
class Lane
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'lanes'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pool $pool = null;
    #[ORM\Column(length: 60)]
    private string $name = '';
    #[ORM\Column(nullable: true)]
    private ?float $maxDepth = null;

    public function getId(): ?int { return $this->id; }
    public function getPool(): ?Pool { return $this->pool; }
    public function setPool(Pool $p): static { $this->pool = $p; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getMaxDepth(): ?float { return $this->maxDepth; }
    public function setMaxDepth(?float $d): static { $this->maxDepth = $d; return $this; }
}
