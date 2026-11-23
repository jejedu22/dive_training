<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Lien public, en lecture seule, vers le planning (sans compte). Supprimer le lien le révoque. */
#[ORM\Entity]
class ShareLink
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    /** Jeton aléatoire présent dans l'adresse. */
    #[ORM\Column(length: 64, unique: true)]
    private string $token;
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    /** Dernier jour de validité (inclus) ; null = sans limite. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;
    /** Code d'accès (haché) demandé à l'ouverture du lien ; null = lien seul. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $codeHash = null;
    /** Codes erronés consécutifs : au-delà de MAX_ATTEMPTS, le lien est bloqué LOCK_MINUTES. */
    #[ORM\Column(options: ['default' => 0])]
    private int $failedAttempts = 0;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    /** Lien vers le livret d'un stagiaire (null = lien vers le planning). */
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Diver $diver = null;
    /** Consultations : dernière ouverture et nombre d'ouvertures. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastOpenedAt = null;
    #[ORM\Column(options: ['default' => 0])]
    private int $openCount = 0;

    public const CODE_MIN = 4;
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    public function __construct()
    {
        $this->token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getToken(): string { return $this->token; }
    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $l): static { $this->label = ($l !== null && trim($l) !== '') ? trim($l) : null; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): static { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $d): static { $this->expiresAt = $d; return $this; }
    public function isValid(\DateTimeImmutable $today): bool
    {
        return !$this->expiresAt || $this->expiresAt->format('Y-m-d') >= $today->format('Y-m-d');
    }

    public function hasCode(): bool { return $this->codeHash !== null; }
    public function setCode(?string $code): static
    {
        $this->codeHash = ($code !== null && $code !== '') ? password_hash($code, \PASSWORD_DEFAULT) : null;
        $this->failedAttempts = 0;
        $this->lockedUntil = null;
        return $this;
    }
    public function isLocked(\DateTimeImmutable $now): bool { return $this->lockedUntil !== null && $this->lockedUntil > $now; }
    public function getLockedUntil(): ?\DateTimeImmutable { return $this->lockedUntil; }
    /** Vérifie le code ; compte les échecs et bloque le lien après MAX_ATTEMPTS. */
    public function checkCode(?string $code, \DateTimeImmutable $now): bool
    {
        if ($this->codeHash === null) return true;
        if ($code !== null && $code !== '' && password_verify($code, $this->codeHash)) {
            $this->failedAttempts = 0;
            $this->lockedUntil = null;
            return true;
        }
        if ($code !== null && $code !== '') {
            if (++$this->failedAttempts >= self::MAX_ATTEMPTS) {
                $this->failedAttempts = 0;
                $this->lockedUntil = $now->modify('+' . self::LOCK_MINUTES . ' minutes');
            }
        }
        return false;
    }
    public function getDiver(): ?Diver { return $this->diver; }
    public function setDiver(?Diver $d): static { $this->diver = $d; return $this; }
    public function getLastOpenedAt(): ?\DateTimeImmutable { return $this->lastOpenedAt; }
    public function getOpenCount(): int { return $this->openCount; }
    public function markOpened(): void { $this->lastOpenedAt = new \DateTimeImmutable(); $this->openCount++; }
}
