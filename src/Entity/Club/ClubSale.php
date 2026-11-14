<?php

declare(strict_types=1);

namespace App\Entity\Club;

use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\Club\ClubSaleStatus;
use App\Repository\Club\ClubSaleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * An admin buying a club out of a player's account — the authorisation, the figure, and the receipt.
 *
 * THIS ROW EXISTS BECAUSE THE CLUB WILL NOT. ClubSaleService credits the account and then deletes
 * the club and everything pointing at it, so by the time anyone asks "what did we pay for what,
 * and who said so", the only surviving evidence would otherwise be a single UserLedger row whose
 * `club_id` has been nulled by its own ON DELETE SET NULL. That is not enough to answer a support
 * ticket: it carries an amount and a note, but not the club's name, not the valuation it came
 * from, and not the operator who authorised it.
 *
 * IT IS ALSO THE QUEUE'S WORK ORDER, and that is why it is written BEFORE the message is
 * dispatched rather than by the handler. The message carries nothing but this row's id — the
 * house rule for every message in this codebase, so a duplicate delivery is a no-op — which only
 * works if the figure and the note are already durable when it goes out. An operator's decision
 * must not live inside a Messenger envelope.
 *
 * NO FK TO THE CLUB, and this is the part most likely to be "fixed" by someone later. `clubId`
 * and `clubName` are plain strings precisely so they outlive the row they describe; a real
 * association would be SET NULL at best and would take the club's identity with it, defeating the
 * whole purpose above. Same technique, and the same reasoning, as SeasonRatingsSnapshot::$clubId
 * and PlotClaim::$clubNameAtClaim.
 *
 * THE ADMIN IS RECORDED AS ID *AND* EMAIL, for the same reason. `Admin` rows are deletable, and
 * an operator who leaves should not erase the record of what they authorised.
 *
 * ONE SALE PER CLUB, enforced by a partial unique index on `club_id` where the status is not
 * `failed` — see ClubSaleRepository. A FAILED row is excluded so a genuine retry is possible
 * after the cause is fixed, rather than needing a hand-deleted row.
 *
 * `estimatePence` IS KEPT ALONGSIDE `agreedPence` on purpose. The gap between what the model
 * offered and what the operator paid is the most useful number in here for retuning the model —
 * if every sale is adjusted upward by a third, GENEROSITY_MULTIPLIER is wrong.
 */
#[ORM\Entity(repositoryClass: ClubSaleRepository::class)]
#[ORM\Table(name: 'club_sale')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_club_sale_user_created')]
#[ORM\Index(columns: ['status'], name: 'idx_club_sale_status')]
// Partial, not plain: a FAILED attempt must not block the retry that fixes it.
#[ORM\UniqueConstraint(
    name: 'uq_club_sale_club_live',
    columns: ['club_id'],
    options: ['where' => "((status)::text <> 'failed'::text)"],
)]
class ClubSale
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /**
     * The account being paid. CASCADE: if the whole account is erased, its buyout records go
     * with it — unlike the club, the user is not meant to outlive this row.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The club's id as a bare string. NO FK — see the class docblock. */
    #[ORM\Column(name: 'club_id', length: 36)]
    private string $clubId;

    /** Frozen at authorisation, because the club row is about to be deleted. */
    #[ORM\Column(length: 100)]
    private string $clubName;

    /** What ClubSaleValuationService offered, before the operator touched it. */
    #[ORM\Column(type: 'bigint')]
    private int $estimatePence;

    /** What the operator authorised. This is the figure that gets paid. */
    #[ORM\Column(type: 'bigint')]
    private int $agreedPence;

    /**
     * The valuation's full working, verbatim, plus its model version.
     *
     * Stored as written so retuning the model is a version bump rather than an archaeology
     * exercise — the same contract PlotClaimValuation::$workingJson holds.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $workingJson;

    /**
     * The operator's note. PLAYER-VISIBLE.
     *
     * Passed straight to UserLedgerService::append() as the entry's description, which
     * GET /api/account/ledger returns to the account holder alongside the type's own label. So
     * it is worded for the player, not for the admin who typed it.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ledgerNote = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $adminId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $adminEmail = null;

    #[ORM\Column(length: 20, enumType: ClubSaleStatus::class)]
    private ClubSaleStatus $status = ClubSaleStatus::PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason = null;

    /**
     * The credit this sale wrote. SET NULL — the money outlives this record, not the reverse.
     *
     * Null on a PENDING row, and null on a FAILED one that never got as far as paying.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserLedger $ledgerEntry = null;

    /**
     * Rows deleted per table by the scrub.
     *
     * The only record that the deletion happened and how much it took with it. Written after the
     * scrub commits; null means the scrub did not run.
     *
     * @var array<string, int>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $scrubCountsJson = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @param array<string, mixed> $workingJson */
    public function __construct(
        User $user,
        string $clubId,
        string $clubName,
        int $estimatePence,
        int $agreedPence,
        array $workingJson,
        ?string $ledgerNote = null,
        ?string $adminId = null,
        ?string $adminEmail = null,
    ) {
        $this->id            = new UuidV7();
        $this->user          = $user;
        $this->clubId        = $clubId;
        $this->clubName      = mb_substr($clubName, 0, 100);
        $this->estimatePence = $estimatePence;
        $this->agreedPence   = $agreedPence;
        $this->workingJson   = $workingJson;
        $this->ledgerNote    = $ledgerNote === null ? null : mb_substr($ledgerNote, 0, 255);
        $this->adminId       = $adminId;
        $this->adminEmail    = $adminEmail;
        $this->createdAt     = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getClubId(): string { return $this->clubId; }
    public function getClubName(): string { return $this->clubName; }
    public function getEstimatePence(): int { return (int) $this->estimatePence; }
    public function getAgreedPence(): int { return (int) $this->agreedPence; }

    /** @return array<string, mixed> */
    public function getWorkingJson(): array { return $this->workingJson; }

    public function getLedgerNote(): ?string { return $this->ledgerNote; }
    public function getAdminId(): ?string { return $this->adminId; }
    public function getAdminEmail(): ?string { return $this->adminEmail; }
    public function getStatus(): ClubSaleStatus { return $this->status; }
    public function getFailureReason(): ?string { return $this->failureReason; }
    public function getLedgerEntry(): ?UserLedger { return $this->ledgerEntry; }

    /** @return array<string, int>|null */
    public function getScrubCountsJson(): ?array { return $this->scrubCountsJson; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    public function isPending(): bool { return $this->status === ClubSaleStatus::PENDING; }
    public function isCompleted(): bool { return $this->status === ClubSaleStatus::COMPLETED; }

    /**
     * The credit is recorded the moment it lands, separately from completion.
     *
     * Called between the ledger write and the scrub, so a crash in the scrub still leaves a row
     * that names the entry it paid. Without this the recovery question — "did this sale already
     * pay?" — would have to be answered by searching the ledger by source ref.
     */
    public function recordLedgerEntry(UserLedger $entry): void
    {
        $this->ledgerEntry = $entry;
    }

    /** @param array<string, int> $scrubCounts */
    public function markCompleted(array $scrubCounts): void
    {
        $this->status          = ClubSaleStatus::COMPLETED;
        $this->scrubCountsJson = $scrubCounts;
        $this->completedAt     = new \DateTimeImmutable();
        $this->failureReason   = null;
    }

    public function markFailed(string $reason): void
    {
        $this->status        = ClubSaleStatus::FAILED;
        $this->failureReason = mb_substr($reason, 0, 255);
    }

    /** Pretty-printed, for the admin detail page only. */
    public function getWorkingPretty(): string
    {
        return (string) json_encode($this->workingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
