<?php

declare(strict_types=1);

namespace App\Service\Leaderboard;

use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Enum\MessageSenderType;
use App\Repository\ClubRepository;
use App\Service\InboxService;
use App\Service\Notification\PushNotificationService;
use Psr\Log\LoggerInterface;

/**
 * Turns a frozen archive row into a prize the player can claim.
 *
 * There is NO new claim mechanism, no new apply path and no new client endpoint. An award
 * becomes exactly what a competition win already becomes — an InboxMessage whose
 * offerData.effects the existing accept path applies. GET /api/inbox and
 * POST /api/inbox/{id}/accept need no change whatsoever.
 *
 * ── senderType MUST be COMPETITION. Naming the trap. ────────────────────────────────────
 * InboxService::acceptMessage() routes ONLY COMPETITION and FRIENDLY to
 * acceptCompetitionReward(). A SYSTEM message carrying offerData.effects renders
 * requiresAccept: true in InboxController::serializeMessage() and then hits `default =>
 * null` on accept — the player taps Accept and gets nothing, with no error anywhere.
 * "The system awarded you a leaderboard prize" reads like system mail, which is precisely
 * why someone will one day "tidy" this to SYSTEM. It must not be.
 *
 * And do NOT add a new MessageSenderType case. senderType is serialized to a live client,
 * so a new case needs no migration — the cost is not schema, it is contract. A case the
 * client has never seen changes how the message renders, and this repo is backend-only.
 * COMPETITION already means "you won something, accept to claim", and the discriminator
 * clients are documented to key off is offerData.type, which carries LEADERBOARD_AWARD
 * either way. So the semantic distinction lives in the field designed to carry it, at zero
 * client risk. LeaderboardAwardDeliveryServiceTest pins this END-TO-END, by feeding the
 * delivered message through the real acceptMessage() and asserting the balance moved — a
 * senderType assertion alone would pass a rename to SYSTEM.
 *
 * ── Never mutates Club ─────────────────────────────────────────────────────────────────
 * Same reasoning as RewardApplierService: SyncService::process() does an absolute
 * Club::setBalance($request->balance), so a server-side addFunds() on the cron's schedule
 * would just be overwritten by the next sync. The accept path is what makes the subsequent
 * overwrite correct, because the player's device learns about the money first.
 *
 * ── No UserLedger row, deliberately ────────────────────────────────────────────────────
 * UserLedger's before/after chain is a dividend-draw (club→user transfer) ledger. A prize
 * paid into a club balance is not a user-level balance change, and inserting one would
 * corrupt UserLedgerRepository::getCurrentBalance() and the landing page's Rich List.
 */
class LeaderboardAwardDeliveryService
{
    public function __construct(
        private readonly InboxService $inboxService,
        private readonly ClubRepository $clubRepository,
        private readonly PushNotificationService $pushNotificationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Marks each prize-winning entry awarded and queues its inbox message. Persists only —
     * the caller flushes everything, including the archive's own status, in one go.
     *
     * A place is awarded when ALL of:
     *   - the definition configures a placement for that place
     *   - the entry at that rank is award_eligible (see the baseline-missing rule)
     *   - its growth is at least the definition's minimum_score
     *   - the placement actually pays something
     *   - the club still exists
     *
     * @param  LeaderboardPeriodArchiveEntry[] $entries in rank order
     * @return LeaderboardPeriodArchiveEntry[] the entries that were awarded
     */
    public function awardPlaces(
        LeaderboardAwardDefinition $definition,
        LeaderboardPeriodArchive $archive,
        array $entries,
        \DateTimeImmutable $now,
    ): array {
        $awarded = [];

        foreach ($entries as $entry) {
            $placement = $definition->getPlacementForPlace($entry->getRank());

            if ($placement === null) {
                continue;
            }

            if (!$entry->isAwardEligible()) {
                $this->logger->info('leaderboard.award.skipped', [
                    'reason' => 'not award-eligible (no baseline, club predates the period)',
                    'period' => $archive->getPeriodKey(),
                    'rank'   => $entry->getRank(),
                    'club'   => $entry->getClubName(),
                ]);

                continue;
            }

            // Growth of 0 is a real and common value on a quiet board, which is exactly why
            // minimum_score exists: without it a dead chart pays an idle club.
            if ($entry->getScore() < $definition->getMinimumScore()) {
                continue;
            }

            $effects = $placement->toEffects();

            if ($effects === []) {
                continue;
            }

            $club = $this->clubRepository->find($entry->getClubId());

            if ($club === null) {
                // The archive row stays, unawarded — it is a record of the rank, and the
                // rank happened. There is simply nobody left to pay.
                $this->logger->warning('leaderboard.award.skipped', [
                    'reason' => 'club no longer exists',
                    'period' => $archive->getPeriodKey(),
                    'clubId' => $entry->getClubId(),
                ]);

                continue;
            }

            $entry->markAwarded($entry->getRank(), $effects, $placement->getRewardTemplate());

            $message = $this->inboxService->createMessage(
                club: $club,
                // COMPETITION, never SYSTEM — see the class docblock. This is the one line
                // in the feature whose "tidying" silently breaks the payout.
                senderType: MessageSenderType::COMPETITION,
                senderName: $archive->getDefinitionName(),
                subject: $this->subjectFor($archive, $entry->getRank()),
                body: $this->bodyFor($archive, $entry),
                offerData: $this->offerDataFor($archive, $entry, $effects),
                relatedEntityType: 'LeaderboardPeriodArchiveEntry',
                relatedEntityId: (string) $entry->getId(),
            );

            // Written back in the same flush as the message itself, so the link is
            // navigable both ways in admin.
            $entry->linkInboxMessage((string) $message->getId());

            $awarded[] = $entry;
        }

        return $awarded;
    }

    /**
     * One push per winner, AFTER the close has committed: a push for an award that was
     * rolled back cannot be unsent.
     *
     * An unrecognised `type` is contractually a client-side no-op that still displays, so
     * no client release is needed for this to be safe.
     *
     * @param LeaderboardPeriodArchiveEntry[] $awarded
     */
    public function notifyWinners(
        LeaderboardAwardDefinition $definition,
        LeaderboardPeriodArchive $archive,
        array $awarded,
    ): void {
        foreach ($awarded as $entry) {
            $userId = $entry->getUserId();

            if ($userId === null) {
                continue;
            }

            $this->pushNotificationService->notifyUsers(
                [$userId],
                $this->subjectFor($archive, $entry->getRank()),
                sprintf(
                    '%s placed %s on %s. Open your inbox to claim the prize.',
                    $entry->getClubName(),
                    self::ordinal($entry->getRank()),
                    $archive->getDefinitionName(),
                ),
                [
                    // Uppercase, matching ROUND_DRAWN / COMPETITION_COMPLETED / MATCH_RESULT.
                    // (ClubSpotlightService uses lowercase club_spotlight; the uppercase
                    // majority wins.)
                    'type'           => 'LEADERBOARD_AWARD',
                    'category'       => $archive->getCategory()->value,
                    'period'         => $archive->getPeriodKey(),
                    'place'          => (string) $entry->getRank(),
                    'archiveEntryId' => (string) $entry->getId(),
                ],
            );
        }
    }

    /**
     * The claim envelope.
     *
     * `effects` AT THE TOP LEVEL is load-bearing twice over: acceptCompetitionReward()
     * reads $offerData['effects'], and serializeMessage() derives requiresAccept from its
     * non-emptiness. Nesting it anywhere else produces a message that renders as claimable
     * and pays nothing.
     *
     * @param  list<array<string, mixed>> $effects
     * @return array<string, mixed>
     */
    private function offerDataFor(LeaderboardPeriodArchive $archive, LeaderboardPeriodArchiveEntry $entry, array $effects): array
    {
        return [
            'type'           => 'LEADERBOARD_AWARD',
            'link'           => [
                'kind'     => 'leaderboard',
                'category' => $archive->getCategory()->value,
                'period'   => $archive->getPeriodKey(),
            ],
            'effects'        => $effects,
            'category'       => $archive->getCategory()->value,
            'categoryLabel'  => self::categoryLabel($archive),
            'period'         => $archive->getPeriodKey(),
            'periodLabel'    => LeaderboardPeriod::label($archive->getPeriodKey()),
            'cadence'        => $archive->getCadence()->value,
            'place'          => $entry->getRank(),
            'score'          => $entry->getScore(),
            'archiveEntryId' => (string) $entry->getId(),
        ];
    }

    private function subjectFor(LeaderboardPeriodArchive $archive, int $place): string
    {
        return sprintf(
            'You placed %s — %s, %s',
            self::ordinal($place),
            self::categoryLabel($archive),
            LeaderboardPeriod::label($archive->getPeriodKey()),
        );
    }

    private function bodyFor(LeaderboardPeriodArchive $archive, LeaderboardPeriodArchiveEntry $entry): string
    {
        return sprintf(
            '%s finished %s in %s for %s with a score of %s. Accept to claim your prize.',
            $entry->getClubName(),
            self::ordinal($entry->getRank()),
            $archive->getDefinitionName(),
            LeaderboardPeriod::label($archive->getPeriodKey()),
            number_format($entry->getScore()),
        );
    }

    private static function categoryLabel(LeaderboardPeriodArchive $archive): string
    {
        return ucwords(str_replace('_', ' ', $archive->getCategory()->value));
    }

    private static function ordinal(int $n): string
    {
        $suffix = match (true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1                    => 'st',
            $n % 10 === 2                    => 'nd',
            $n % 10 === 3                    => 'rd',
            default                          => 'th',
        };

        return $n . $suffix;
    }
}
