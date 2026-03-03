<?php

namespace App\Entity;

use App\Enum\CompanySize;
use App\Enum\InvestorTier;
use App\Repository\InvestorRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: InvestorRepository::class)]
class Investor
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

    #[ORM\ManyToOne(inversedBy: 'investors')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Academy $academy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', enumType: InvestorTier::class, options: ['default' => 'angel'])]
    private InvestorTier $tier = InvestorTier::ANGEL;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $investmentAmount = 0;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, options: ['default' => '5.00'])]
    private string $percentageOwned = '5.00';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $investedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPayoutAt = null;

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

    public function getTier(): InvestorTier { return $this->tier; }
    public function setTier(InvestorTier $tier): void { $this->tier = $tier; }

    public function getInvestmentAmount(): int { return $this->investmentAmount; }
    public function setInvestmentAmount(int $amount): void { $this->investmentAmount = $amount; }

    public function getPercentageOwned(): float { return (float) $this->percentageOwned; }
    public function setPercentageOwned(float $percentage): void { $this->percentageOwned = number_format($percentage, 2, '.', ''); }

    public function getInvestedAt(): ?\DateTimeImmutable { return $this->investedAt; }
    public function setInvestedAt(?\DateTimeImmutable $investedAt): void { $this->investedAt = $investedAt; }

    public function getLastPayoutAt(): ?\DateTimeImmutable { return $this->lastPayoutAt; }
    public function setLastPayoutAt(?\DateTimeImmutable $lastPayoutAt): void { $this->lastPayoutAt = $lastPayoutAt; }

    public function getExpectedReturnPercentage(): int
    {
        return match ($this->size) {
            CompanySize::SMALL  => 5,
            CompanySize::MEDIUM => 10,
            CompanySize::LARGE  => 20,
        };
    }

    public function calculateAnnualPayout(int $annualProfit): int
    {
        return (int) round($annualProfit * $this->getPercentageOwned() / 100);
    }

    public function getBuybackPrice(): int
    {
        return (int) round($this->investmentAmount * 1.3);
    }
}
