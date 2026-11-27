<?php

declare(strict_types=1);

namespace App\Entity\WorldPack;

use App\Entity\Club;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Repository\WorldPack\WorldPackSetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One generation of a country's world — every league tier's pack (rows in
 * country_world_pack_cache, FK'd to this row) PLUS the starter squad drawn for it,
 * generated together and claimable by exactly ONE club.
 *
 * Why this exists at all: country_world_pack_cache used to be keyed (country, tier)
 * with no owner, so every player in a country was served the same byte-identical
 * world forever, and the starter squad was a completely separate live draw that could
 * fail (412 InsufficientStarterPoolException) against a country whose packs were warm.
 * Binding the two into one claimable unit fixes both: a pack can never exist without
 * its starter squad, and no two clubs can be served the same one. The squad half stopped being
 * a draw entirely on 2026-11-17 — it is generated, like every NPC club's — so the 412 named
 * above is no longer reachable from anywhere.
 *
 * **The whole set is claimed at POST /api/initialize/starter, not per tier.** That is
 * load-bearing, not tidiness: the client treats an empty or truncated 2xx as a
 * retryable transport failure, so a retried POST /api/initialize/league/{tier} MUST
 * return the same payload it returned the first time, and a device's eight tiers must
 * never be mixed across two generation runs (different fixtures, different NPC ids).
 * Claiming per tier would satisfy "unique" and still hand out an incoherent world.
 *
 * `starterPackJson` is nullable for exactly one reason: the rows backfilled by the
 * introducing migration, one per country, wrap the pre-existing cache rows so set_id
 * could become NOT NULL without a rebuild. Those sets are claimable and fall back to
 * the live draw — that is what makes the deploy a no-op for every pack already warm.
 * A set produced by a generation run always has one.
 */
#[ORM\Entity(repositoryClass: WorldPackSetRepository::class)]
#[ORM\Table(name: 'world_pack_set')]
#[ORM\Index(columns: ['country', 'status'], name: 'idx_world_pack_set_country_status')]
// Serves the claim's WHERE and its ORDER BY in one index.
#[ORM\Index(columns: ['country', 'status', 'payload_version', 'generated_at'], name: 'idx_world_pack_set_claimable')]
// A club can never hold two sets. PARTIAL, because every unclaimed row has a NULL here and
// a plain unique index would admit only one of them.
#[ORM\UniqueConstraint(
    name: 'uq_world_pack_set_one_per_club',
    columns: ['claimed_by_club_id'],
    options: ['where' => '(claimed_by_club_id IS NOT NULL)'],
)]
class WorldPackSet
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 2, options: ['fixed' => true])]
    private string $country;

    /**
     * Stamped from WorldInitializationService::WORLD_PACK_VERSION at creation. The claim
     * query filters on it, so a set built before a shape change is never handed out —
     * same invalidation mechanism CountryWorldPackCache::$payloadVersion has always had,
     * lifted to the unit that is actually served.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $payloadVersion;

    #[ORM\Column(type: 'string', length: 20, enumType: WorldPackSetStatus::class)]
    private WorldPackSetStatus $status;

    /** @var array{players: array, staff: array, scouts: array}|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $starterPackJson = null;

    /**
     * The set's free agents (pack v16, Courtney 2026-11-27): unattached players of the pack's
     * nationality, served once by POST /api/initialize/free-agents and loaded into the save's
     * free transfer list. Cleared by the maintain sweep with the tier payloads, so the list
     * lives on the device only once the world has loaded. Null on sets built before v16 and
     * on claimed sets after the sweep.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $freeAgentsJson = null;

    /**
     * How many tiers this set is expected to hold, recorded at creation from the
     * country's League rows. Stored rather than counted so "ready" is verifiable
     * against an intent — a country that loses a League mid-run would otherwise make
     * a short set look complete.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $tierCount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    /**
     * SET NULL rather than CASCADE: deleting a club must not take its world with it,
     * because the set row is the audit trail of which club was served which generation
     * long after the prune has dropped the payloads.
     */
    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(name: 'claimed_by_club_id', nullable: true, onDelete: 'SET NULL')]
    private ?Club $claimedByClub = null;

    public function __construct(
        string $country,
        int $payloadVersion,
        ?array $starterPackJson = null,
        int $tierCount = 0,
    ) {
        $this->id              = new UuidV7();
        $this->country         = $country;
        $this->payloadVersion  = $payloadVersion;
        $this->starterPackJson = $starterPackJson;
        $this->tierCount       = $tierCount;
        $this->status          = WorldPackSetStatus::BUILDING;
        $this->generatedAt     = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getCountry(): string { return $this->country; }
    public function getPayloadVersion(): int { return $this->payloadVersion; }
    public function getStatus(): WorldPackSetStatus { return $this->status; }
    public function getStarterPackJson(): ?array { return $this->starterPackJson; }
    public function getFreeAgentsJson(): ?array { return $this->freeAgentsJson; }
    public function getTierCount(): int { return $this->tierCount; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
    public function getReadyAt(): ?\DateTimeImmutable { return $this->readyAt; }
    public function getClaimedAt(): ?\DateTimeImmutable { return $this->claimedAt; }
    public function getClaimedByClub(): ?Club { return $this->claimedByClub; }

    public function setStatus(WorldPackSetStatus $status): static { $this->status = $status; return $this; }
    public function setStarterPackJson(?array $pack): static { $this->starterPackJson = $pack; return $this; }
    public function setFreeAgentsJson(?array $players): static { $this->freeAgentsJson = $players; return $this; }
    public function setTierCount(int $count): static { $this->tierCount = $count; return $this; }
    public function setErrorMessage(?string $message): static { $this->errorMessage = $message; return $this; }
    public function setReadyAt(?\DateTimeImmutable $at): static { $this->readyAt = $at; return $this; }
    public function setClaimedAt(?\DateTimeImmutable $at): static { $this->claimedAt = $at; return $this; }
    public function setClaimedByClub(?Club $club): static { $this->claimedByClub = $club; return $this; }

    /** Claimable: ready, unclaimed, and carrying a payload shape the current code still serves. */
    public function isClaimable(int $currentPayloadVersion): bool
    {
        return $this->status === WorldPackSetStatus::READY
            && $this->claimedByClub === null
            && $this->payloadVersion === $currentPayloadVersion
            // A migration-backfilled set wraps pre-existing payloads and has no squad.
            // Claiming one would buy the club nothing and burn a set, so it is not stock.
            && $this->starterPackJson !== null;
    }
}
