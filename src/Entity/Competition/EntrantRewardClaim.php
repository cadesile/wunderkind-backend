<?php

namespace App\Entity\Competition;

use App\Repository\Competition\EntrantRewardClaimRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Audit/idempotency ledger for applied rewards — distinct from delivery, which happens
 * via InboxMessage (see RewardApplierService). rewardTemplate is nullable: victor-prize
 * and entry-fee claims are template-intrinsic amounts with no RewardTemplate row; only
 * claims from attached RewardTemplates reference one.
 *
 * Uniqueness on (entrant, rewardTemplate, triggerContext) can't be a single plain unique
 * constraint because rewardTemplate is nullable and Postgres treats NULLs as distinct in
 * a standard unique index — enforced instead via two hand-added partial unique indexes
 * in the migration (uq_entrant_reward_claim_with_template /
 * uq_entrant_reward_claim_without_template).
 */
#[ORM\Entity(repositoryClass: EntrantRewardClaimRepository::class)]
#[ORM\Table(name: 'entrant_reward_claim')]
#[ORM\Index(columns: ['entrant_id', 'reward_template_id', 'trigger_context'], name: 'idx_entrant_reward_claim_lookup')]
class EntrantRewardClaim
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: CompetitionEntrant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CompetitionEntrant $entrant;

    #[ORM\ManyToOne(targetEntity: RewardTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RewardTemplate $rewardTemplate = null;

    /** e.g. 'victor_prize', or a RewardTemplate.slug-referencing tag. */
    #[ORM\Column(length: 40)]
    private string $triggerContext;

    /** @var list<array<string, mixed>> Frozen copy of effects actually applied/queued for delivery. */
    #[ORM\Column(type: 'json')]
    private array $appliedEffectsJson;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $claimedAt;

    public function __construct(CompetitionEntrant $entrant, ?RewardTemplate $rewardTemplate, string $triggerContext, array $appliedEffectsJson)
    {
        $this->id                = new UuidV7();
        $this->entrant             = $entrant;
        $this->rewardTemplate        = $rewardTemplate;
        $this->triggerContext          = $triggerContext;
        $this->appliedEffectsJson        = $appliedEffectsJson;
        $this->claimedAt                  = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getEntrant(): CompetitionEntrant { return $this->entrant; }

    public function getRewardTemplate(): ?RewardTemplate { return $this->rewardTemplate; }

    public function getTriggerContext(): string { return $this->triggerContext; }

    public function getAppliedEffectsJson(): array { return $this->appliedEffectsJson; }

    public function getClaimedAt(): \DateTimeImmutable { return $this->claimedAt; }
}
