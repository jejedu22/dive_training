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
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;
    /** Couleur de l'avatar choisie par l'utilisateur (#rrggbb). */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $avatarColor = null;
    /** Préférences d'affichage (voir DEFAULT_PREFERENCES). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $preferences = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public const ROLES = ['ROLE_INSTRUCTOR', 'ROLE_REFERENT', 'ROLE_ADMIN'];
    /** Préférences et valeurs autorisées (la première est la valeur par défaut). */
    public const PREFERENCES = [
        'homePage' => ['planning', 'sections'],
        'planningView' => ['month', 'week'],
        'onlyMine' => [false, true],
    ];
    private const AVATAR_COLORS = ['#0b6aa8', '#1e8449', '#7d3c98', '#c0392b', '#d35400', '#0e8a96', '#3949ab', '#8d5524'];

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

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
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $p): static { $this->phone = ($p !== null && trim($p) !== '') ? trim($p) : null; return $this; }
    public function getAvatarColor(): string { return $this->avatarColor ?? self::AVATAR_COLORS[($this->id ?? 0) % count(self::AVATAR_COLORS)]; }
    public function setAvatarColor(?string $c): static { $this->avatarColor = $c !== null ? strtolower($c) : null; return $this; }
    /** Préférences complètes (valeurs par défaut pour celles non choisies). */
    public function getPreferences(): array
    {
        $out = [];
        // une valeur qui n'existe plus (ancienne version) reprend la valeur par défaut
        foreach (self::PREFERENCES as $key => $allowed) $out[$key] = in_array($this->preferences[$key] ?? null, $allowed, true) ? $this->preferences[$key] : $allowed[0];
        return $out;
    }
    public function setPreferences(array $p): static { $this->preferences = $p; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(\DateTimeImmutable $d): static { $this->lastLoginAt = $d; return $this; }
    public function isReferent(): bool { return in_array('ROLE_REFERENT', $this->roles, true) || $this->isAdmin(); }
}
