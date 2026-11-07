<?php
namespace App\Entity;

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

    public function getId(): ?int { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): static { $this->firstName = $v; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): static { $this->lastName = $v; return $this; }
    public function getLevel(): ?string { return $this->level; }
    public function setLevel(?string $v): static { $this->level = $v; return $this; }
}
