<?php

declare(strict_types=1);

namespace App\Entity\Leaderboard;

use App\Entity\Competition\RewardTemplate;
use App\Repository\Leaderboard\LeaderboardAwardPlacementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One prize place on a definition — "1st gets £50,000 and 25 reputation".
 *
 * A real child entity with a real FK, not a json place→reward map on the definition.
 * Three reasons: referential integrity to RewardTemplate (a slug in json dangles); a
 * CollectionField plus a plain FormType is EasyAdmin's native idiom, whereas
 * Field::new('json')->setFormType() hits the documented injected-CollectionType-options
 * trap (CLAUDE.md Key Gotchas) and needs a form theme with a {% block ..._widget %}; and
 * CompetitionTemplate already relates to RewardTemplate by FK, so this is consistent. It
 * also removes the need for a bespoke json-shape validator.
 *
 * prizePence and reputationDelta are synthesized into `ledger_delta` / `reputation_delta`
 * GameEffects at award time — the CompetitionTemplate::$victorPrize precedent, where the
 * common cash case needs no RewardTemplate row at all. rewardTemplate covers anything
 * richer.
 */
#[ORM\Entity(repositoryClass: LeaderboardAwardPlacementRepository::class)]
#[ORM\Table(name: 'leaderboard_award_placement')]
#[ORM\UniqueConstraint(name: 'uq_leaderboard_award_placement_def_place', columns: ['definition_id', 'place'])]
class LeaderboardAwardPlacement
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /**
     * CASCADE, unlike the archive's SET NULL: a placement is config, not history, and is
     * meaningless without the definition that gave it a cadence and a category.
     */
    #[ORM\ManyToOne(targetEntity: LeaderboardAwardDefinition::class, inversedBy: 'placements')]
    #[ORM\JoinColumn(name: 'definition_id', nullable: false, onDelete: 'CASCADE')]
    private ?LeaderboardAwardDefinition $definition = null;

    /** 1-based. */
    #[ORM\Column(type: 'smallint')]
    private int $place = 1;

    #[ORM\Column(name: 'prize_pence', type: 'bigint', options: ['default' => 0])]
    private int $prizePence = 0;

    #[ORM\Column(name: 'reputation_delta', type: 'integer', options: ['default' => 0])]
    private int $reputationDelta = 0;

    /**
     * SET NULL, not CASCADE: deleting a reward template must not silently delete a prize
     * place and quietly stop the definition awarding at that rank.
     */
    #[ORM\ManyToOne(targetEntity: RewardTemplate::class)]
    #[ORM\JoinColumn(name: 'reward_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?RewardTemplate $rewardTemplate = null;

    public function __construct(int $place = 1)
    {
        $this->id    = new UuidV7();
        $this->place = $place;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getDefinition(): ?LeaderboardAwardDefinition { return $this->definition; }
    public function setDefinition(?LeaderboardAwardDefinition $definition): static { $this->definition = $definition; return $this; }

    public function getPlace(): int { return $this->place; }
    public function setPlace(int $place): static { $this->place = max(1, $place); return $this; }

    public function getPrizePence(): int { return $this->prizePence; }
    public function setPrizePence(int $prizePence): static { $this->prizePence = $prizePence; return $this; }

    public function getReputationDelta(): int { return $this->reputationDelta; }
    public function setReputationDelta(int $reputationDelta): static { $this->reputationDelta = $reputationDelta; return $this; }

    public function getRewardTemplate(): ?RewardTemplate { return $this->rewardTemplate; }
    public function setRewardTemplate(?RewardTemplate $rewardTemplate): static { $this->rewardTemplate = $rewardTemplate; return $this; }

    /**
     * The effects this place pays, as GameEffect payloads for InboxService's existing
     * accept path.
     *
     * Only an ACTIVE RewardTemplate contributes, so deactivating a template stops it
     * paying out without unwiring every definition that references it.
     *
     * @return list<array<string, mixed>>
     */
    public function toEffects(): array
    {
        $effects = [];

        if ($this->prizePence !== 0) {
            $effects[] = ['type' => 'ledger_delta', 'amountPence' => $this->prizePence];
        }

        if ($this->reputationDelta !== 0) {
            $effects[] = ['type' => 'reputation_delta', 'amount' => $this->reputationDelta];
        }

        if ($this->rewardTemplate !== null && $this->rewardTemplate->isActive()) {
            foreach ($this->rewardTemplate->getEffects() as $effect) {
                $effects[] = $effect;
            }
        }

        return $effects;
    }

    public function __toString(): string
    {
        return 'Place ' . $this->place;
    }
}
