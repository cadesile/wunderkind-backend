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
        $template = $activeCompetition->getTemplate();

        if ($template->getVictorPrize() > 0) {
            $this->claimAndDeliver(
                $winner,
                null,
                'victor_prize',
                [['type' => 'ledger_delta', 'amountPence' => $template->getVictorPrize()]],
                $template->getName(),
            );
        }

        foreach ($template->getRewardTemplates() as $rewardTemplate) {
            $effects = $rewardTemplate->getEffects();
            if ($effects === []) {
                continue;
            }
            $this->claimAndDeliver($winner, $rewardTemplate, $rewardTemplate->getSlug(), $effects, $template->getName());
        }
    }

    /** @param list<array<string, mixed>> $effects */
    private function claimAndDeliver(
        CompetitionEntrant $entrant,
        ?RewardTemplate $rewardTemplate,
        string $triggerContext,
        array $effects,
        string $templateName,
    ): void {
        $id  = new UuidV7();
        $now = new \DateTimeImmutable();

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

        if ($affected === 0) {
            return;
        }

        $message = new InboxMessage(
            club:       $entrant->getClub(),
            senderType: MessageSenderType::COMPETITION,
            senderName: $templateName,
            subject:    $triggerContext === 'victor_prize' ? "You won the {$templateName}!" : "Reward from the {$templateName}",
            body:       $triggerContext === 'victor_prize'
                ? "Congratulations — your club won the {$templateName}. Accept to claim your prize."
                : "You've earned a reward from the {$templateName}. Accept to claim it.",
        );
        $message->setOfferData(['effects' => $effects]);
        $message->setRelatedEntityType('EntrantRewardClaim');
        $message->setRelatedEntityId((string) $id);

        $this->em->persist($message);
    }
}
