<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Cursus modifié par un administrateur (ou créé par le club). Sans enregistrement, le cursus garde
 * les valeurs par défaut du MFT (App\Training\Mft::defaults()).
 */
#[ORM\Entity]
class CurriculumDefinition
{
    #[ORM\Id, ORM\Column(length: 10)]
    private string $code;
    #[ORM\Column(type: 'json')]
    private array $data = [];
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $updatedBy = null;

    public function __construct(string $code) { $this->code = $code; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCode(): string { return $this->code; }
    public function getData(): array { return $this->data; }
    public function setData(array $d, ?string $by): static { $this->data = $d; $this->updatedAt = new \DateTimeImmutable(); $this->updatedBy = $by; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedBy(): ?string { return $this->updatedBy; }
}
