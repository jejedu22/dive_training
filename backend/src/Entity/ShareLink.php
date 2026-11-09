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
}
