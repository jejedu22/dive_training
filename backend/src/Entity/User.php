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
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public const ROLES = ['ROLE_INSTRUCTOR', 'ROLE_REFERENT', 'ROLE_ADMIN'];

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $e): static { $this->email = mb_strtolower(trim($e)); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getRoles(): array { return array_unique([...$this->roles, 'ROLE_INSTRUCTOR']); }
    public function setRoles(array $r): static { $this->roles = array_values(array_intersect(array_unique($r), self::ROLES)); return $this; }
    /** Rôles stockés (sans le ROLE_INSTRUCTOR implicite). */
    public function getStoredRoles(): array { return $this->roles; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $a): static { $this->active = $a; return $this; }
    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->roles, true); }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $p): static { $this->password = $p; return $this; }
    public function getUserIdentifier(): string { return $this->email; }
    public function eraseCredentials(): void {}
    public function isReferent(): bool { return in_array('ROLE_REFERENT', $this->roles, true) || $this->isAdmin(); }
}
