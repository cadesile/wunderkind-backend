<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class SyncRequest
{
    #[Assert\Positive]
    public int $weekNumber;

    #[Assert\NotBlank]
    public string $clientTimestamp;

    #[Assert\PositiveOrZero]
    public float $earningsDelta;

    public float $reputationDelta = 0;

    #[Assert\PositiveOrZero]
    public float $hallOfFamePoints = 0;

    /**
     * Transfer summaries for players bought/sold during this tick.
     *
     * @var TransferSyncDto[]
     */
    #[Assert\Valid]
    #[Assert\All([new Assert\Type(TransferSyncDto::class)])]
    public array $transfers = [];

    /**
     * Archival snapshot of this week's financial ledger.
     *
     * @var LedgerEntrySyncDto[]
     */
    #[Assert\Valid]
    #[Assert\All([new Assert\Type(LedgerEntrySyncDto::class)])]
    public array $ledger = [];

    /**
     * @param array<array{playerId?: string, playerName?: string, destinationClub?: string, grossFee?: int, agentCommission?: int, netProceeds?: int, type?: string}|TransferSyncDto> $transfers
     */
    public function setTransfers(array $transfers): void
    {
        $this->transfers = array_map(static function (array|TransferSyncDto $item): TransferSyncDto {
            if ($item instanceof TransferSyncDto) {
                return $item;
            }
            $dto = new TransferSyncDto();
            $dto->playerId = $item['playerId'] ?? '';
            $dto->playerName = $item['playerName'] ?? '';
            $dto->destinationClub = $item['destinationClub'] ?? '';
            $dto->grossFee = (int) ($item['grossFee'] ?? 0);
            $dto->agentCommission = (int) ($item['agentCommission'] ?? 0);
            $dto->netProceeds = (int) ($item['netProceeds'] ?? 0);
            $dto->type = $item['type'] ?? '';
            return $dto;
        }, $transfers);
    }

    /**
     * @param array<array{category?: string, amount?: int, description?: string}|LedgerEntrySyncDto> $ledger
     */
    public function setLedger(array $ledger): void
    {
        $this->ledger = array_map(static function (array|LedgerEntrySyncDto $item): LedgerEntrySyncDto {
            if ($item instanceof LedgerEntrySyncDto) {
                return $item;
            }
            $dto = new LedgerEntrySyncDto();
            $dto->category = $item['category'] ?? '';
            $dto->amount = (int) ($item['amount'] ?? 0);
            $dto->description = $item['description'] ?? '';
            return $dto;
        }, $ledger);
    }

    /**
     * Manager personality shift deltas sent by the client each week.
     * Keys: 'temperament', 'discipline', 'ambition'. Values: signed int deltas.
     * Example: {"temperament": 2, "discipline": -1, "ambition": 0}
     *
     * @var array<string, int>
     */
    public array $managerShifts = [];

    /**
     * Player attribute snapshots sent by the client each week.
     * Each entry: {playerId, pace, technical, vision, power, stamina, heart, height, weight, morale}
     *
     * @var array<array{playerId: string, pace?: int, technical?: int, vision?: int, power?: int, stamina?: int, heart?: int, height?: int, weight?: int, morale?: int}>
     */
    public array $players = [];
}
