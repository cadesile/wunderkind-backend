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
 * Applies rewards as rounds complete: each non-final round's winners get their share of
 * the victor prize (applyRoundPrize), and the Final's winner gets the full prize. Synthesizes the template's
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

    /**
     * The cash a single winner of round $roundIndex (1-based) earns, given the template's
     * victorPrize (pence). The final pays the full victorPrize; every earlier round pays half
     * of the round after it. With a £2m prize: final £2m, semi-final £1m, quarter-final £500k.
     * Floored to whole pounds so deep early rounds never pay odd pence.
     */
    public static function roundPrizePence(int $victorPrize, int $roundIndex, int $totalRounds): int
    {
        if ($victorPrize <= 0 || $roundIndex < 1 || $roundIndex > $totalRounds) {
            return 0;
        }
        $divisor = 2 ** ($totalRounds - $roundIndex);
        if ($divisor === 1) {
            return $victorPrize;
        }

        return intdiv(intdiv($victorPrize, $divisor), 100) * 100;
    }

    /**
     * Pays a non-final round's winner their share of the victor prize (see roundPrizePence()),
     * delivered the same way as the champion's prize: an inbox offer the player accepts.
     * Idempotent per entrant and round through the claim's trigger_context.
     */
    public function applyRoundPrize(CompetitionEntrant $winner, ActiveCompetition $activeCompetition, int $roundIndex, string $roundLabel): void
    {
        $winnerClub = $winner->getClub();
        if ($winnerClub === null) {
            return;
        }

        $template    = $activeCompetition->getTemplate();
        $totalRounds = count(BracketLabeler::labelsForCapacity($activeCompetition->getEntrantCapacity()));
        if ($roundIndex >= $totalRounds) {
            return; // the final is paid by applyVictorPrize()
        }

        $amount = self::roundPrizePence($template->getVictorPrize(), $roundIndex, $totalRounds);
        if ($amount <= 0) {
            return;
        }

        $effects = [['type' => 'ledger_delta', 'amountPence' => $amount]];
        $claimId = $this->recordClaim($winner, null, 'round_prize:' . $roundIndex, $effects, new \DateTimeImmutable());
        if ($claimId === null) {
            return;
        }

        $templateName  = $template->getName();
        $competitionId = (string) $activeCompetition->getId();
        $roundName     = self::roundName($roundLabel);
        $pounds        = '£' . number_format(intdiv($amount, 100));

        $message = new InboxMessage(
            club:       $winnerClub,
            senderType: MessageSenderType::COMPETITION,
            senderName: $templateName,
            subject:    "{$roundName} prize: {$pounds}",
            body:       "Your club won its {$roundName} tie in the {$templateName} and earned {$pounds}. Accept to claim it.",
        );
        $message->setOfferData([
            'type'          => 'COMPETITION_ROUND_WON',
            'link'          => ['kind' => 'competition', 'id' => $competitionId],
            'effects'       => $effects,
            'competitionId' => $competitionId,
            'roundIndex'    => $roundIndex,
            'roundLabel'    => $roundLabel,
        ]);
        $message->setRelatedEntityType('EntrantRewardClaim');
        $message->setRelatedEntityId((string) $claimId);

        $this->em->persist($message);
    }

    private static function roundName(string $label): string
    {
        return match ($label) {
            'SF'    => 'Semi-final',
            'QF'    => 'Quarter-final',
            'FINAL' => 'Final',
            default => preg_match('/^R(\d+)$/', $label, $m) === 1 ? "Round of {$m[1]}" : $label,
        };
    }

    public function applyVictorPrize(CompetitionEntrant $winner, ActiveCompetition $activeCompetition): void
    {
        // The prize is delivered as an inbox offer the player accepts, so there is nothing
        // to deliver — and no one to deliver it to — when the winning entry's club has been
        // deleted. An entry outlives its club (CompetitionEntrant::$club) precisely so the
        // bracket can still show who won; that is all it can do here.
        $winnerClub = $winner->getClub();
        if ($winnerClub === null) {
            return;
        }

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
            club:       $winnerClub,
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
