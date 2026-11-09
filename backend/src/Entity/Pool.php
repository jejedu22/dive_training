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
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $lanes;

    public function __construct() { $this->lanes = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getLanes(): Collection { return $this->lanes; }
    public function addLane(Lane $l): static
    {
        $max = -1;
        foreach ($this->lanes as $x) $max = max($max, $x->getPosition());
        $this->lanes->add($l);
        $l->setPool($this)->setPosition($max + 1); // en fin de bassin
        return $this;
    }
    public function removeLane(Lane $l): static { $this->lanes->removeElement($l); return $this; }
}
