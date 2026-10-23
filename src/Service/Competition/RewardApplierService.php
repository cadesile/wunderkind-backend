<?php

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\RewardTemplate;
use App\Entity\InboxMessage;
use App\Enum\MessageSenderType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Applies rewards when the Final round completes. Synthesizes the template's
 * victorPrize as a ledger_delta effect (no RewardTemplate row needed for the common
 * "cash prize" case), plus gathers effects from every RewardTemplate attached to the
 * template ("ability to link one or more RewardTemplate entities upon completion").
 *
 * Never mutates Club directly — every effect is delivered via InboxMessage and only
 * applied when the player accepts it (see InboxService::acceptCompetitionReward). A
 * server-side Club::addFunds() call on the round processor's own schedule would just
 * be overwritten by the entrant's next sync, since SyncService::process() does an
 * absolute Club::setBalance($request->balance), not an accumulate.
 */
class RewardApplierService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function applyVictorPrize(CompetitionEntrant $winner, ActiveCompetition $activeCompetition): void
    {
        $template        = $activeCompetition->getTemplate();
        $combinedEffects = [];
        $firstClaimId    = null;
        $now             = new \DateTimeImmutable();

        if ($template->getVictorPrize() > 0) {
            $victorEffects = [['type' => 'ledger_delta', 'amountPence' => $template->getVictorPrize()]];
            $claimId       = $this->recordClaim($winner, null, 'victor_prize', $victorEffects, $now);
            if ($claimId !== null) {
                $firstClaimId    ??= $claimId;
                $combinedEffects = array_merge($combinedEffects, $victorEffects);
            }
        }

        foreach ($template->getRewardTemplates() as $rewardTemplate) {
            $effects = $rewardTemplate->getEffects();
            if ($effects === []) {
                continue;
            }
            $claimId = $this->recordClaim($winner, $rewardTemplate, $rewardTemplate->getSlug(), $effects, $now);
            if ($claimId !== null) {
                $firstClaimId    ??= $claimId;
                $combinedEffects = array_merge($combinedEffects, $effects);
            }
        }

        if ($combinedEffects === [] || $firstClaimId === null) {
            return;
        }

        $templateName  = $template->getName();
        $competitionId = (string) $activeCompetition->getId();

        $message = new InboxMessage(
            club:       $winner->getClub(),
            senderType: MessageSenderType::COMPETITION,
            senderName: $templateName,
            subject:    "You won the {$templateName}!",
            body:       "Congratulations — your club won the {$templateName}. Accept to claim your prize.",
        );
        $message->setOfferData([
            'type'          => 'COMPETITION_WON',
            'link'          => ['kind' => 'competition', 'id' => $competitionId],
            'effects'       => $combinedEffects,
            'competitionId' => $competitionId,
        ]);
        $message->setRelatedEntityType('EntrantRewardClaim');
        $message->setRelatedEntityId((string) $firstClaimId);

        $this->em->persist($message);
    }

    /** @param list<array<string, mixed>> $effects */
    private function recordClaim(
        CompetitionEntrant $entrant,
        ?RewardTemplate $rewardTemplate,
        string $triggerContext,
        array $effects,
        \DateTimeImmutable $now,
    ): ?UuidV7 {
        $id = new UuidV7();

        // Idempotency backstop against a round-processor retry re-applying the Final's
        // reward: the two partial unique indexes on entrant_reward_claim (with/without
        // reward_template_id) both feed a single un-targeted ON CONFLICT DO NOTHING.
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            INSERT INTO entrant_reward_claim (id, entrant_id, reward_template_id, trigger_context, applied_effects_json, claimed_at)
            VALUES (:id, :entrantId, :rewardTemplateId, :triggerContext, :effects, :claimedAt)
            ON CONFLICT DO NOTHING
            SQL,
            [
                'id'               => $id->toRfc4122(),
                'entrantId'        => $entrant->getId()->toRfc4122(),
                'rewardTemplateId' => $rewardTemplate?->getId()->toRfc4122(),
                'triggerContext'   => $triggerContext,
                'effects'          => json_encode($effects, JSON_THROW_ON_ERROR),
                'claimedAt'        => $now->format('Y-m-d H:i:s'),
            ],
        );

        return $affected > 0 ? $id : null;
    }
}
