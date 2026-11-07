<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Encadrant. Seuls les encadrants ont accès à l'application. */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';
    #[ORM\Column(length: 120)]
    private string $name = '';
    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $roles = [];
    #[ORM\Column]
    private string $password = '';

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $e): static { $this->email = $e; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getRoles(): array { return array_unique([...$this->roles, 'ROLE_INSTRUCTOR']); }
    public function setRoles(array $r): static { $this->roles = $r; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $p): static { $this->password = $p; return $this; }
    public function getUserIdentifier(): string { return $this->email; }
    public function eraseCredentials(): void {}
    public function isReferent(): bool { return in_array('ROLE_REFERENT', $this->getRoles(), true) || in_array('ROLE_ADMIN', $this->getRoles(), true); }
}
