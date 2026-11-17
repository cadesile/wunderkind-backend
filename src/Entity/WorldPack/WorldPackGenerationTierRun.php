<?php

namespace App\Entity\WorldPack;

use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use App\Repository\WorldPack\WorldPackGenerationTierRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One row per tier within a WorldPackGenerationRun. country/tier are denormalized
 * off the run for query convenience. agentPoolIds is the bounded agent subset
 * selected once for this tier (WorldInitializationService::selectBoundedAgentPool()),
 * stored here so every club message in the tier reads the same fixed subset rather
 * than re-rolling it per club.
 *
 * claimedAt is the assembly claim lock — same atomic-conditional-UPDATE idiom as
 * CompetitionRound's drawLockedAt/resolveLockedAt.
 *
 * `set` is which WorldPackSet this tier's payload belongs to. It lives here rather than
 * on the run because one run builds a country's whole buffer of sets at once, and the
 * two can succeed independently — one set can go READY while the other fails. Keeping
 * the run per-country also leaves its single-flight index untouched.
 *
 * **CASCADE, not SET NULL, and the reason is subtle enough to be worth the paragraph.**
 * SET NULL looks kinder — it would keep the run history after a per-set delete — but it is
 * incompatible with the indexes below. One run covers several sets, so deleting a country's
 * stock nulls the `set_id` of several tier runs that share a `(run_id, tier)`; they then all
 * collide on the partial index, and an ordinary admin "delete this country's sets" fails with
 * a unique violation from a trigger nobody was looking at. CASCADE resolves the conflict
 * instead of hiding it, and what it costs is the per-tier detail of a set an operator chose to
 * delete — history that no longer describes anything reachable. The run row itself survives.
 *
 * `set` is still nullable, for rows that never had a set (hand-built, and anything predating
 * sets); it is simply never nulled after the fact.
 *
 * The uniqueness key is (run_id, set_id, tier) — and because Postgres treats NULLs as
 * DISTINCT, that index does not constrain set-less rows at all, so there is a SECOND,
 * partial index covering (run_id, tier) WHERE set_id IS NULL. Both are needed: without the
 * first, two sets of one run could not hold the same tier, which is the normal case now;
 * without the second, the duplicate-tier guarantee silently disappeared for every row with
 * no set.
 */
#[ORM\Entity(repositoryClass: WorldPackGenerationTierRunRepository::class)]
#[ORM\Table(name: 'world_pack_generation_tier_run')]
#[ORM\UniqueConstraint(name: 'uq_world_pack_generation_tier_run_run_set_tier', columns: ['run_id', 'set_id', 'tier'])]
// AND a partial companion for the set-less rows, because the index above does NOT cover them.
// Postgres treats NULLs as DISTINCT in a unique index, so (run, NULL, 3) twice is two
// different keys and the duplicate the index above looks like it forbids slips straight
// through. One tier run per (run, tier) is still the guarantee for a row with no set, and
// WorldPackGenerationRunSchemaTest asserts exactly that.
#[ORM\UniqueConstraint(
    name: 'uq_world_pack_generation_tier_run_run_tier_no_set',
    columns: ['run_id', 'tier'],
    options: ['where' => '(set_id IS NULL)'],
)]
class WorldPackGenerationTierRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: WorldPackGenerationRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private WorldPackGenerationRun $run;

    #[ORM\ManyToOne(targetEntity: WorldPackSet::class)]
    #[ORM\JoinColumn(name: 'set_id', nullable: true, onDelete: 'CASCADE')]
    private ?WorldPackSet $set = null;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column(type: 'string', enumType: WorldPackGenerationTierRunStatus::class)]
    private WorldPackGenerationTierRunStatus $status;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $totalClubCount = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $completedClubCount = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $failedClubCount = 0;

    /** @var string[]|null Bounded agent-pool UUIDs for this tier, set once at tier-claim time. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $agentPoolIds = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function __construct(WorldPackGenerationRun $run, string $country, int $tier, ?WorldPackSet $set = null)
    {
        $this->id      = new UuidV7();
        $this->run      = $run;
        $this->country  = $country;
        $this->tier      = $tier;
        $this->set       = $set;
        $this->status     = WorldPackGenerationTierRunStatus::PENDING;
    }

    public function getSet(): ?WorldPackSet { return $this->set; }
    public function setSet(?WorldPackSet $set): static { $this->set = $set; return $this; }

    public function getId(): UuidV7 { return $this->id; }

    public function getRun(): WorldPackGenerationRun { return $this->run; }

    public function getCountry(): string { return $this->country; }

    public function getTier(): int { return $this->tier; }

    public function getStatus(): WorldPackGenerationTierRunStatus { return $this->status; }
    public function setStatus(WorldPackGenerationTierRunStatus $status): static { $this->status = $status; return $this; }

    public function getTotalClubCount(): int { return $this->totalClubCount; }
    public function setTotalClubCount(int $totalClubCount): static { $this->totalClubCount = $totalClubCount; return $this; }

    public function getCompletedClubCount(): int { return $this->completedClubCount; }
    public function setCompletedClubCount(int $completedClubCount): static { $this->completedClubCount = $completedClubCount; return $this; }

    public function getFailedClubCount(): int { return $this->failedClubCount; }
    public function setFailedClubCount(int $failedClubCount): static { $this->failedClubCount = $failedClubCount; return $this; }

    /** @return string[]|null */
    public function getAgentPoolIds(): ?array { return $this->agentPoolIds; }
    /** @param string[] $agentPoolIds */
    public function setAgentPoolIds(array $agentPoolIds): static { $this->agentPoolIds = $agentPoolIds; return $this; }

    public function getClaimedAt(): ?\DateTimeImmutable { return $this->claimedAt; }
    public function setClaimedAt(?\DateTimeImmutable $claimedAt): static { $this->claimedAt = $claimedAt; return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage === null ? null : mb_substr($errorMessage, 0, 2000); return $this; }
}
