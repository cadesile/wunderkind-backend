<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Entity\UserLedger;
use App\Repository\Location\PlotClaimValuationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * How a sell-back figure was arrived at — the working, kept so support can reverse it.
 *
 * A SPLIT ROW, for the reason schema.md gives for the seven that already exist: ORM 3 removed
 * PARTIAL hydration, so every mapped column is decoded on every read of its row. `workingJson`
 * is written once and read only when someone disputes a payout, while PlotClaim is read in bulk
 * by admin screens and by the API's `claimedBy` lookup.
 *
 * PlotClaim declares NO inverse association to this, deliberately: a OneToOne inverse side cannot
 * be proxied (the owning FK is here) and is therefore always fetched eagerly regardless of
 * fetch: LAZY, which would silently restore the whole cost the split removes. Fetch through
 * PlotClaimValuationRepository, in bulk where more than one is needed.
 *
 * WHY IT MUST CAPTURE THE CURRENT LAND VALUE, NOT JUST THE ORIGINAL. Land is revalued over time.
 * A claim already records what was PAID, which is fixed — but a sell-back figure also depends on
 * what the land is worth NOW, and that number will have moved again by the time anyone asks about
 * it. Without the snapshot, a payout is unreproducible a week later, which is exactly when a
 * support ticket arrives.
 *
 * A REVERSAL NEVER EDITS THIS ROW or the ledger entry it describes. It stamps `reversedAt` and
 * points at a new, opposing UserLedger row — so the original figure and the correction are both
 * permanently legible.
 */
#[ORM\Entity(repositoryClass: PlotClaimValuationRepository::class)]
#[ORM\Table(name: 'plot_claim_valuation')]
class PlotClaimValuation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private PlotClaim $claim;

    /**
     * Everything the valuation read and every coefficient it applied.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $workingJson;

    #[ORM\Column(length: 20)]
    private string $modelVersion;

    #[ORM\Column]
    private \DateTimeImmutable $computedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reversedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reversalReason = null;

    /** The opposing ledger row a reversal wrote. SET NULL — the money outlives this record. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserLedger $reversalLedgerEntry = null;

    /** @param array<string, mixed> $workingJson */
    public function __construct(PlotClaim $claim, array $workingJson, string $modelVersion)
    {
        $this->id           = new UuidV7();
        $this->claim        = $claim;
        $this->workingJson  = $workingJson;
        $this->modelVersion = $modelVersion;
        $this->computedAt   = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClaim(): PlotClaim { return $this->claim; }

    /** @return array<string, mixed> */
    public function getWorkingJson(): array { return $this->workingJson; }

    public function getModelVersion(): string { return $this->modelVersion; }
    public function getComputedAt(): \DateTimeImmutable { return $this->computedAt; }
    public function getReversedAt(): ?\DateTimeImmutable { return $this->reversedAt; }
    public function getReversalReason(): ?string { return $this->reversalReason; }
    public function getReversalLedgerEntry(): ?UserLedger { return $this->reversalLedgerEntry; }

    public function isReversed(): bool { return $this->reversedAt !== null; }

    public function markReversed(string $reason, ?UserLedger $reversalEntry = null): void
    {
        $this->reversedAt          = new \DateTimeImmutable();
        $this->reversalReason      = mb_substr($reason, 0, 255);
        $this->reversalLedgerEntry = $reversalEntry;
    }

    /** Pretty-printed, for the admin detail page only. */
    public function getWorkingPretty(): string
    {
        return (string) json_encode($this->workingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
