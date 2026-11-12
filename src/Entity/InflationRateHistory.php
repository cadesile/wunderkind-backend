<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InflationRateHistoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Every change the inflation rate has ever had, append-only.
 *
 * WHY THE RATE NEEDS A HISTORY WHEN NO OTHER ECONOMY FIGURE DOES. Changing a tier factor or a
 * turnover baseline re-prices what the backend GENERATES from then on; the old value has no
 * lingering meaning and `game_config` holding only the current one is honest. The inflation
 * rate is different: the app compounds it over a club's season count, so a save that has run
 * twenty seasons has been living under whatever the rate was for each of them. Change the rate
 * and every existing save's prices move retroactively — and with only the current value stored
 * there is no way to answer "why is this club's ceiling what it is" afterwards.
 *
 * APPEND-ONLY, AND NOTHING READS IT TO COMPUTE ANYTHING. The index is always derived from the
 * CURRENT rate in `game_config`, never replayed from this table. That is the locked decision
 * and it is worth not quietly improving: a replayed history would make a club's ceiling depend
 * on when an operator happened to click save, which is unexplainable to a player and
 * untestable. This table is for the operator and the auditor, not the arithmetic.
 *
 * `previousRatePerSeasonBp` is stored rather than inferred from the row before it. The rows are
 * ordered by a timestamp, and reconstructing "what it was before" from an ordering is exactly
 * the kind of derivation that goes wrong the first time two changes land in the same second.
 *
 * `changedBy` is nullable with ON DELETE SET NULL: an audit row must outlive the admin account
 * that wrote it, the same reasoning as UserLedger::$sourceSyncRecord.
 */
#[ORM\Entity(repositoryClass: InflationRateHistoryRepository::class)]
#[ORM\Table(name: 'inflation_rate_history')]
#[ORM\Index(columns: ['recorded_at'], name: 'idx_inflation_rate_history_recorded')]
class InflationRateHistory
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'integer')]
    private int $ratePerSeasonBp;

    /** Null only for the very first recorded change, where there is no prior value. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $previousRatePerSeasonBp;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: 'changed_by_admin_id', nullable: true, onDelete: 'SET NULL')]
    private ?Admin $changedBy;

    /** Why, in the operator's own words. The one field a later reader actually wants. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function __construct(
        int $ratePerSeasonBp,
        ?int $previousRatePerSeasonBp = null,
        ?Admin $changedBy = null,
        ?string $note = null,
        ?\DateTimeImmutable $recordedAt = null,
    ) {
        $this->id                     = new UuidV7();
        $this->ratePerSeasonBp        = $ratePerSeasonBp;
        $this->previousRatePerSeasonBp = $previousRatePerSeasonBp;
        $this->changedBy              = $changedBy;
        $this->note                   = ($note !== null && trim($note) !== '') ? trim($note) : null;
        $this->recordedAt             = $recordedAt ?? new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getRatePerSeasonBp(): int { return $this->ratePerSeasonBp; }

    public function getPreviousRatePerSeasonBp(): ?int { return $this->previousRatePerSeasonBp; }

    public function getChangedBy(): ?Admin { return $this->changedBy; }

    public function getNote(): ?string { return $this->note; }

    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
}
