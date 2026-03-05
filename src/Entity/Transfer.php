<?php

namespace App\Entity;

use App\Enum\TransferType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: \App\Repository\TransferRepository::class)]
#[ORM\Index(columns: ['academy_id', 'occurred_at'], name: 'idx_transfer_academy_occurred')]
#[ORM\Index(columns: ['net_proceeds'], name: 'idx_transfer_net_proceeds')]
#[ORM\HasLifecycleCallbacks]
class Transfer
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Player $player = null;

    #[ORM\ManyToOne(inversedBy: 'transfers')]
    #[ORM\JoinColumn(nullable: false)]
    private Academy $academy;

    /** Name of the buying club (external, not another academy in our system) */
    #[ORM\Column(length: 100)]
    private string $destinationClubName;

    #[ORM\Column(enumType: TransferType::class)]
    private TransferType $type;

    /** Transfer fee in pence/cents */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $fee = 0;

    /** Agent commission in pence/cents */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $agentCommission = 0;

    /** Net proceeds stored from frontend (fee minus commission, in pence) */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $netProceeds = 0;

    /** CA points gained at this academy (currentCA - joiningCA) */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $developmentPoints = 0;

    /** Reputation awarded to the academy for this sale */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $reputationGained = 0;

    /** Destination club name for display (alias for destinationClubName) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $buyingClub = null;

    /** When the transfer occurred on the client (game time) */
    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** When the record was synced to the server */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    public function __construct(
        ?Player $player,
        Academy $academy,
        string $destinationClubName,
        TransferType $type,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->id                  = new UuidV7();
        $this->player              = $player;
        $this->academy             = $academy;
        $this->destinationClubName = $destinationClubName;
        $this->type                = $type;
        $this->occurredAt          = $occurredAt;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getPlayer(): ?Player { return $this->player; }
    public function getAcademy(): Academy { return $this->academy; }

    public function getDestinationClubName(): string { return $this->destinationClubName; }
    public function setDestinationClubName(string $name): void { $this->destinationClubName = $name; }

    public function getType(): TransferType { return $this->type; }
    public function getTypeValue(): string { return $this->type->value; }

    public function getFee(): int { return $this->fee; }
    public function setFee(int $fee): void { $this->fee = $fee; }

    public function getAgentCommission(): int { return $this->agentCommission; }
    public function setAgentCommission(int $commission): void { $this->agentCommission = $commission; }

    public function getNetProceeds(): int { return $this->netProceeds; }
    public function setNetProceeds(int $proceeds): void { $this->netProceeds = $proceeds; }

    public function getDevelopmentPoints(): int { return $this->developmentPoints; }
    public function setDevelopmentPoints(int $points): void { $this->developmentPoints = $points; }

    public function getReputationGained(): int { return $this->reputationGained; }
    public function setReputationGained(int $rep): void { $this->reputationGained = $rep; }

    public function getBuyingClub(): ?string { return $this->buyingClub; }
    public function setBuyingClub(?string $club): void { $this->buyingClub = $club; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function getSyncedAt(): ?\DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(?\DateTimeImmutable $at): void { $this->syncedAt = $at; }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validate(): void
    {
        if ($this->fee < 0) {
            throw new \InvalidArgumentException('Transfer fee cannot be negative');
        }
        if ($this->agentCommission < 0) {
            throw new \InvalidArgumentException('Agent commission cannot be negative');
        }
        if ($this->agentCommission > $this->fee) {
            throw new \InvalidArgumentException('Agent commission cannot exceed transfer fee');
        }
    }
}
