<?php

namespace App\Entity;

use App\Enum\TransferType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
class Transfer
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Player $player;

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

    /** When the transfer occurred on the client (game time) */
    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** When the record was synced to the server */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    public function __construct(
        Player $player,
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

    public function getPlayer(): Player { return $this->player; }
    public function getAcademy(): Academy { return $this->academy; }

    public function getDestinationClubName(): string { return $this->destinationClubName; }
    public function setDestinationClubName(string $name): void { $this->destinationClubName = $name; }

    public function getType(): TransferType { return $this->type; }
    public function getTypeValue(): string { return $this->type->value; }

    public function getFee(): int { return $this->fee; }
    public function setFee(int $fee): void { $this->fee = $fee; }

    public function getAgentCommission(): int { return $this->agentCommission; }
    public function setAgentCommission(int $commission): void { $this->agentCommission = $commission; }

    /** Net proceeds to the academy after agent cut */
    public function getNetProceeds(): int { return $this->fee - $this->agentCommission; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function getSyncedAt(): ?\DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(?\DateTimeImmutable $at): void { $this->syncedAt = $at; }
}
