<?php

namespace App\Entity;

use App\Enum\CompanySize;
use App\Repository\SponsorRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: SponsorRepository::class)]
class Sponsor
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 150)]
    private string $company;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(type: 'string', enumType: CompanySize::class)]
    private CompanySize $size = CompanySize::MEDIUM;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Academy $academy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $company)
    {
        $this->id        = new UuidV7();
        $this->company   = $company;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getCompany(): string { return $this->company; }
    public function setCompany(string $company): void { $this->company = $company; }

    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }

    public function getSize(): CompanySize { return $this->size; }
    public function setSize(CompanySize $size): void { $this->size = $size; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): void { $this->isActive = $isActive; }

    public function isInMarketPool(): bool { return $this->academy === null; }

    public function getAcademy(): ?Academy { return $this->academy; }
    public function setAcademy(?Academy $academy): void { $this->academy = $academy; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getExpectedReturnPercentage(): int
    {
        return match ($this->size) {
            CompanySize::SMALL  => 5,
            CompanySize::MEDIUM => 10,
            CompanySize::LARGE  => 20,
        };
    }
}
