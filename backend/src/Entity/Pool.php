<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Bassin / fosse. */
#[ORM\Entity]
class Pool
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 120)]
    private string $name = '';
    /** @var Collection<int, Lane> */
    #[ORM\OneToMany(mappedBy: 'pool', targetEntity: Lane::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lanes;

    public function __construct() { $this->lanes = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getLanes(): Collection { return $this->lanes; }
    public function addLane(Lane $l): static { $this->lanes->add($l); $l->setPool($this); return $this; }
}
