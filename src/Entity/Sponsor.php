<?php

namespace App\Entity;

use App\Enum\CompanySize;
use App\Enum\SponsorStatus;
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

    #[ORM\ManyToOne(inversedBy: 'sponsors')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Academy $academy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $monthlyPayment = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $contractStartDate = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $contractEndDate = null;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $reputationMinThreshold = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $reputationBonusThreshold = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, options: ['default' => '1.00'])]
    private string $bonusMultiplier = '1.00';

    #[ORM\Column(type: 'string', enumType: SponsorStatus::class, options: ['default' => 'active'])]
    private SponsorStatus $status = SponsorStatus::ACTIVE;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $earlyTerminationFee = null;

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

    public function getMonthlyPayment(): int { return $this->monthlyPayment; }
    public function setMonthlyPayment(int $monthlyPayment): void { $this->monthlyPayment = $monthlyPayment; }

    public function getContractStartDate(): ?\DateTimeImmutable { return $this->contractStartDate; }
    public function setContractStartDate(?\DateTimeImmutable $date): void { $this->contractStartDate = $date; }

    public function getContractEndDate(): ?\DateTimeImmutable { return $this->contractEndDate; }
    public function setContractEndDate(?\DateTimeImmutable $date): void { $this->contractEndDate = $date; }

    public function getReputationMinThreshold(): int { return $this->reputationMinThreshold; }
    public function setReputationMinThreshold(int $threshold): void { $this->reputationMinThreshold = $threshold; }

    public function getReputationBonusThreshold(): ?int { return $this->reputationBonusThreshold; }
    public function setReputationBonusThreshold(?int $threshold): void { $this->reputationBonusThreshold = $threshold; }

    public function getBonusMultiplier(): float { return (float) $this->bonusMultiplier; }
    public function setBonusMultiplier(float $multiplier): void { $this->bonusMultiplier = number_format($multiplier, 2, '.', ''); }

    public function getStatus(): SponsorStatus { return $this->status; }
    public function setStatus(SponsorStatus $status): void { $this->status = $status; }

    public function getEarlyTerminationFee(): ?int { return $this->earlyTerminationFee; }
    public function setEarlyTerminationFee(?int $fee): void { $this->earlyTerminationFee = $fee; }

    public function getExpectedReturnPercentage(): int
    {
        return match ($this->size) {
            CompanySize::SMALL  => 5,
            CompanySize::MEDIUM => 10,
            CompanySize::LARGE  => 20,
        };
    }

    public function getRemainingMonths(): int
    {
        if ($this->contractEndDate === null) {
            return 0;
        }
        $now  = new \DateTimeImmutable();
        $diff = $now->diff($this->contractEndDate);
        if ($this->contractEndDate <= $now) {
            return 0;
        }
        return $diff->y * 12 + $diff->m;
    }

    public function getRemainingValue(): int
    {
        return $this->getRemainingMonths() * $this->monthlyPayment;
    }

    public function calculateEarlyTerminationFee(): int
    {
        return (int) round($this->getRemainingValue() * 0.5);
    }

    public function checkReputationThresholds(int $currentReputation): void
    {
        if ($this->status !== SponsorStatus::ACTIVE) {
            return;
        }

        if ($currentReputation < $this->reputationMinThreshold) {
            $this->status = SponsorStatus::VOIDED;
            return;
        }

        if ($this->reputationBonusThreshold !== null && $currentReputation >= $this->reputationBonusThreshold) {
            $this->bonusMultiplier = number_format(min(2.00, $this->getBonusMultiplier() + 0.1), 2, '.', '');
        }
    }
}
