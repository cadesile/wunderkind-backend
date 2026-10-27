<?php

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionResult;
use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionFixtureStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Enum\MessageSenderType;
use App\Enum\Tier;
use App\Repository\Competition\CompetitionEntrantSquadRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionResultRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Appearance\SeededRng;
use App\Service\ClubInitializationService;
use App\Service\InboxService;
use App\Service\MatchEngine\MatchEngineContext;
use App\Service\MatchEngine\MatchEngineInterface;
use App\Service\MatchEngine\MatchEngineRegistry;
use App\Service\MatchEngine\MatchEngineResult;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns the entire resolution + finalization phase (DRAWN -> RESULTS_PUBLISHED), and, for
 * a non-final round, schedules the next round's draw (writes its scheduledAt — the actual
 * draw itself is CompetitionDrawService's job, on a later tick). Behind
 * app:competition:resolve-rounds (cron every 1 min).
 *
 * The resolve claim (resolveLockedAt) deliberately does NOT flip status away from DRAWN —
 * unlike the draw claim, a round can sit "claimed, partially resolved" for a while under
 * forceResolveFixture() (one admin click per fixture); status only becomes
 * RESULTS_PUBLISHED once every fixture is actually processed, inside finalizeRound(). The
 * resolveLockedAt column alone is sufficient to keep an overlapping scheduled tick out —
 * findDueForResults() still matches a DRAWN row mid-force-resolution, but the claim's
 * `resolveLockedAt IS NULL` condition correctly rejects that second claim.
 */
class CompetitionResultsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionResultRepository $resultRepository,
        private readonly CompetitionEntrantSquadRepository $squadRepository,
        private readonly CompetitionScheduleCalculator $scheduleCalculator,
        private readonly MatchEngineRegistry $matchEngineRegistry,
        private readonly RewardApplierService $rewardApplierService,
        private readonly PushNotificationService $pushNotificationService,
        private readonly CompetitionResponseBuilder $responseBuilder,
        private readonly CompetitionResponseCacheService $responseCache,
        private readonly ?InboxService $inboxService = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @return int Number of rounds this call actually published results for. */
    public function publishDueResults(\DateTimeImmutable $now): int
    {
        $published = 0;
        foreach ($this->roundRepository->findDueForResults($now) as $round) {
            if ($this->claimResults($round, $now)) {
                $this->publishResults($round, $now);
                $this->prewarmResponse($round->getActiveCompetition());
                $published++;
            }
        }

        return $published;
    }

    /**
     * Admin-only test tool: forces a single DRAWN round's PENDING fixture to resolve
     * immediately, bypassing the round's matchesResolveAt wait entirely — for exercising
     * round-by-round competition processing locally without waiting out real durations.
     * Shares the same claim, resolution, and round-finalization logic as the scheduled
     * cron path (publishDueResults()), just triggered per-fixture instead of per-due-round,
     * so a forced result is indistinguishable from a naturally processed one.
     *
     * @throws \RuntimeException if the fixture is already resolved, is a bye (nothing to
     *         simulate — no opposing entrant), or its round isn't currently DRAWN (not yet
     *         drawn, or already RESULTS_PUBLISHED/CANCELLED).
     */
    public function forceResolveFixture(CompetitionFixture $fixture): CompetitionResult
    {
        if ($fixture->getProcessedAt() !== null) {
            throw new \RuntimeException('This fixture has already been resolved.');
        }
        if ($fixture->getHomeEntrant() === null || $fixture->getAwayEntrant() === null) {
            throw new \RuntimeException('This fixture has no opposing entrant yet — nothing to simulate.');
        }

        $round = $fixture->getRound();
        if ($round->getStatus() !== CompetitionRoundStatus::DRAWN) {
            throw new \RuntimeException('This round is no longer processable.');
        }

        $now = new \DateTimeImmutable();

        if ($round->getResolveLockedAt() === null) {
            if (!$this->claimResults($round, $now)) {
                throw new \RuntimeException('Could not claim this round for processing — a scheduled tick may be running it right now. Try again.');
            }
        }

        $this->resolveFixture($fixture, $round, $now);
        $this->em->flush();

        $remainingFixtures  = $this->fixtureRepository->findByRoundOrderedBySlot($round);
        $roundFullyResolved = true;
        $winners             = [];
        foreach ($remainingFixtures as $roundFixture) {
            if ($roundFixture->getProcessedAt() === null) {
                $roundFullyResolved = false;
                break;
            }
            if ($roundFixture->getWinnerEntrant() !== null) {
                $winners[] = $roundFixture->getWinnerEntrant();
            }
        }

        if ($roundFullyResolved) {
            $this->finalizeRound($round, $now, $winners);
        }

        $this->prewarmResponse($round->getActiveCompetition());

        return $this->resultRepository->findOneBy(['fixture' => $fixture])
            ?? throw new \RuntimeException('Fixture was resolved but no result was recorded — this should never happen.');
    }

    /**
     * Claim-lock: an atomic conditional UPDATE, not ORM flush+catch. Deliberately leaves
     * `status` at DRAWN — see class docblock.
     */
    private function claimResults(CompetitionRound $round, \DateTimeImmutable $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE competition_round
               SET resolve_locked_at = :now
             WHERE id = :id
               AND status = :drawn
               AND resolve_locked_at IS NULL
            SQL,
            [
                'now'   => $now->format('Y-m-d H:i:s'),
                'id'    => $round->getId()->toRfc4122(),
                'drawn' => CompetitionRoundStatus::DRAWN->value,
            ],
        );

        if ($affected === 0) {
            return false;
        }

        $this->em->refresh($round);

        return true;
    }

    private function publishResults(CompetitionRound $round, \DateTimeImmutable $now): void
    {
        $winners = [];
        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round) as $fixture) {
            if ($fixture->getProcessedAt() !== null) {
                // Defensive: already resolved by a prior partial run (e.g. forceResolveFixture).
                if ($fixture->getWinnerEntrant() !== null) {
                    $winners[] = $fixture->getWinnerEntrant();
                }
                continue;
            }

            $winner = $this->resolveFixture($fixture, $round, $now);
            if ($winner !== null) {
                $winners[] = $winner;
            }
        }

        $this->finalizeRound($round, $now, $winners);
    }

    /**
     * Post-loop side effects — only called once every fixture in $round has been resolved.
     *
     * @param list<CompetitionEntrant> $winners
     */
    private function finalizeRound(CompetitionRound $round, \DateTimeImmutable $now, array $winners): void
    {
        $activeCompetition = $round->getActiveCompetition();

        $round->setStatus(CompetitionRoundStatus::RESULTS_PUBLISHED);
        $round->setCompletedAt($now);

        if ($this->isFinalRound($round)) {
            // The final's losers aren't passed in $winners — re-derive them from the round's own
            // fixtures (cheap: only ever runs once per competition, when it actually ends) so the
            // runner-up(s) can be told the tournament is over too, not just the champion.
            $runnersUp = [];
            foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round) as $fixture) {
                $fixtureWinner = $fixture->getWinnerEntrant();
                $home          = $fixture->getHomeEntrant();
                $away          = $fixture->getAwayEntrant();
                if ($fixtureWinner !== null && $home !== null && $away !== null) {
                    $runnersUp[] = $fixtureWinner === $home ? $away : $home;
                }
            }

            foreach ($winners as $winner) {
                $winner->setStatus(CompetitionEntrantStatus::WINNER);
                $this->rewardApplierService->applyVictorPrize($winner, $activeCompetition);
            }
            $activeCompetition->setStatus(ActiveCompetitionStatus::COMPLETED);
            $activeCompetition->setCompletedAt($now);

            $this->pushNotificationService->notifyUsers(
                array_map(static fn (CompetitionEntrant $e) => (string) $e->getClub()->getUser()->getId(), $winners),
                'Champions!',
                'You won the tournament! Check your inbox for your prize.',
                ['type' => 'COMPETITION_COMPLETED', 'competitionId' => (string) $activeCompetition->getId(), 'result' => 'WON'],
            );
            if ($runnersUp !== []) {
                $this->pushNotificationService->notifyUsers(
                    array_map(static fn (CompetitionEntrant $e) => (string) $e->getClub()->getUser()->getId(), $runnersUp),
                    'Tournament complete',
                    'The final has been played — the tournament has ended.',
                    ['type' => 'COMPETITION_COMPLETED', 'competitionId' => (string) $activeCompetition->getId(), 'result' => 'RUNNER_UP'],
                );
            }
        } else {
            $this->scheduleNextRoundDraw($round, $now);
        }

        $this->em->flush();

        // Prune the engine inputs only once COMPLETED is actually committed.
        //
        // These rows are the match engine's only source for each entrant's XI, staff and
        // club tuning keys, so dropping them is safe exactly when no further fixture can
        // resolve — and not one statement earlier. deleteForCompetition() is raw SQL and
        // commits on its own, so running it before the flush above would, on a failed
        // flush, leave a competition that is not COMPLETED with no way to resolve its
        // remaining fixtures. Ordering it after is the difference between a pruned finished
        // bracket and a permanently stuck live one.
        if ($activeCompetition->getStatus() === ActiveCompetitionStatus::COMPLETED) {
            $this->squadRepository->deleteForCompetition((string) $activeCompetition->getId());
        }
    }

    /**
     * Writes the next round's scheduledAt = now + intermissionSeconds. Drawing it is
     * CompetitionDrawService's job, on a later tick.
     *
     * **The final is the exception: it is scheduled for `now`, so the next draw tick picks it
     * up immediately.** Once the semi-final has resolved there are exactly two entrants left
     * and therefore exactly one possible fixture — the draw cannot reveal anything, so making
     * the two finalists sit through an intermission before being told who they already know
     * they are playing is dead time. Every other round keeps its intermission, where the draw
     * genuinely resolves which of the remaining winners meet.
     *
     * This sets the clock rather than calling CompetitionDrawService inline on purpose: the
     * draw stays behind its own atomic claim-lock (the single path that moves a round out of
     * DRAW_PENDING), there is no service cycle between results and draw, and the final still
     * gets drawRound()'s normal fixture seeding, inbox messages and push. The cost is up to
     * one cron tick (60s) rather than literal immediacy.
     */
    private function scheduleNextRoundDraw(CompetitionRound $completedRound, \DateTimeImmutable $now): void
    {
        $activeCompetition = $completedRound->getActiveCompetition();
        $nextRound         = $this->roundRepository->findByCompetitionAndIndex($activeCompetition, $completedRound->getRoundIndex() + 1);
        if ($nextRound === null) {
            return;
        }

        $roundCount          = count(BracketLabeler::labelsForCapacity($activeCompetition->getEntrantCapacity()));

        if ($nextRound->getRoundIndex() === $roundCount) {
            $nextRound->setScheduledAt($now);

            return;
        }

        $totalSeconds         = $activeCompetition->getEndsAt()->getTimestamp() - $activeCompetition->getStartsAt()->getTimestamp();
        $intervalSeconds       = $this->scheduleCalculator->intervalSeconds($totalSeconds, $roundCount);
        [, $intermissionSeconds] = $this->scheduleCalculator->splitInterval($intervalSeconds, $activeCompetition->getTemplate()->getIntermissionRatio());

        $nextRound->setScheduledAt($now->modify(sprintf('+%d seconds', $intermissionSeconds)));
    }

    private function resolveFixture(CompetitionFixture $fixture, CompetitionRound $round, \DateTimeImmutable $now): ?CompetitionEntrant
    {
        $home = $fixture->getHomeEntrant();
        $away = $fixture->getAwayEntrant();

        if ($home === null || $away === null) {
            // The no-registration-deadline design means byes shouldn't occur in Phase 1 (capacities
            // are powers of 2, locking only happens on exact capacity fill) — this is a defensive
            // fallback, not an expected path: advance whichever side exists so a stuck edge case
            // doesn't wedge the whole bracket.
            $survivor = $home ?? $away;
            $fixture->setStatus(CompetitionFixtureStatus::BYE);
            $fixture->setWinnerEntrant($survivor);
            $fixture->setProcessedAt($now);

            return $survivor;
        }

        $engine = $this->matchEngineRegistry->resolveFor($round->getMatchEngineIdentifier());

        // Both squads in one query. The engine reads the XI, staff and club tuning keys from
        // these rows and nowhere else, so this is the one place a resolution pass touches
        // competition_entrant_squad.
        $squads      = $this->squadRepository->findByEntrants([$home, $away]);
        $matchResult = $engine->resolve(
            MatchEngineSideFactory::fromEntrant($home, $squads[(string) $home->getId()] ?? null),
            MatchEngineSideFactory::fromEntrant($away, $squads[(string) $away->getId()] ?? null),
            MatchEngineContext::forCupTie((string) $fixture->getId(), $round->getMatchEngineIdentifier()),
        );

        $usedTiebreak = false;
        $winner       = match (true) {
            $matchResult->homeScore > $matchResult->awayScore => $home,
            $matchResult->awayScore > $matchResult->homeScore => $away,
            $matchResult->wentToPenalties => ($matchResult->penaltyHomeScore > $matchResult->penaltyAwayScore ? $home : $away),
            // Defensive fallback only — DeterministicEngine now guarantees a decisive result
            // via extra time + penalties, so this branch is unreachable in practice; kept in
            // case an engine that hasn't implemented ET/penalties yet (e.g. a future real AI
            // tier) ever produces a level score.
            default => (function () use ($home, $away, $fixture, &$usedTiebreak): CompetitionEntrant {
                $usedTiebreak = true;

                return $this->breakTie($home, $away, $fixture);
            })(),
        };
        $loser = $winner === $home ? $away : $home;

        $homeClubJson = $this->clubJsonOf($home);
        $awayClubJson = $this->clubJsonOf($away);

        $result = new CompetitionResult(
            $fixture,
            $matchResult->homeScore,
            $matchResult->awayScore,
            $matchResult->eventLog,
            $round->getMatchEngineIdentifier(),
            $homeClubJson,
            $awayClubJson,
            $matchResult->homeLineup,
            $matchResult->awayLineup,
        );
        $result->setNarrativePayload($matchResult->narrativePayload);
        $result->setShootoutInfo($matchResult->wentToExtraTime, $matchResult->wentToPenalties, $matchResult->penaltyHomeScore, $matchResult->penaltyAwayScore);
        $result->setComputationAuditJson($this->buildComputationAudit(
            $fixture,
            $round,
            $home,
            $away,
            $winner,
            $engine,
            $matchResult,
            $usedTiebreak,
        ));
        $this->em->persist($result);

        $fixture->setStatus(CompetitionFixtureStatus::COMPLETE);
        $fixture->setWinnerEntrant($winner);
        $fixture->setProcessedAt($now);

        $loser->setStatus(CompetitionEntrantStatus::ELIMINATED);
        $loser->setEliminatedInRound($round);

        // Every fixture's result — not just when a round completes — gets its own push, to
        // both sides, each with a title reflecting their own outcome (a distinct "Eliminated"
        // signal rather than the same neutral wording both sides used to get) — but an identical
        // `data` payload for both, so client-side handling (fetch by fixtureId) is unaffected by
        // which side received it. FCM data values must be strings and the message has a hard
        // ~4KB size cap, so (unlike this method's other side effects) the full result payload
        // isn't embedded here — same notify-only contract as ROUND_DRAWN/NEW_REGISTRANT: the
        // client re-fetches via GET /api/competitions/{id}, whose result field is exactly
        // CompetitionResult::toClientSummary()'s shape.
        $scoreline     = sprintf('%s %d-%d %s', $home->getClub()->getName(), $matchResult->homeScore, $matchResult->awayScore, $away->getClub()->getName());
        $competitionId = (string) $round->getActiveCompetition()->getId();
        $fixtureId     = (string) $fixture->getId();
        $templateName  = $round->getActiveCompetition()->getTemplate()->getName();

        if ($this->inboxService !== null) {
            foreach ([$home, $away] as $sideEntrant) {
                $this->inboxService->createMessage(
                    club: $sideEntrant->getClub(),
                    senderType: MessageSenderType::COMPETITION,
                    senderName: $templateName,
                    subject: sprintf('Match Result (%s): %s', $round->getLabel(), $scoreline),
                    body: sprintf('Full-time in the %s of the %s: %s.', $round->getLabel(), $templateName, $scoreline),
                    offerData: [
                        'type'          => 'MATCH_RESULT',
                        'link'          => ['kind' => 'match', 'id' => $fixtureId],
                        'competitionId' => $competitionId,
                        'roundId'       => (string) $round->getId(),
                        'fixtureId'     => $fixtureId,
                    ],
                    relatedEntityType: 'competition_fixture',
                    relatedEntityId: $fixtureId,
                );
            }

            $this->inboxService->createMessage(
                club: $loser->getClub(),
                senderType: MessageSenderType::COMPETITION,
                senderName: $templateName,
                subject: sprintf('Eliminated from %s', $templateName),
                body: sprintf('Your club was knocked out in the %s of the %s (%s).', $round->getLabel(), $templateName, $scoreline),
                offerData: [
                    'type'          => 'COMPETITION_ELIMINATED',
                    'link'          => ['kind' => 'competition', 'id' => $competitionId],
                    'competitionId' => $competitionId,
                    'roundId'       => (string) $round->getId(),
                    'fixtureId'     => $fixtureId,
                ],
                relatedEntityType: 'active_competition',
                relatedEntityId: $competitionId,
            );
        }

        $resultData = [
            'type'          => 'MATCH_RESULT',
            'competitionId' => $competitionId,
            'roundId'       => (string) $round->getId(),
            'fixtureId'     => $fixtureId,
        ];

        $this->pushNotificationService->notifyUsers(
            [(string) $winner->getClub()->getUser()->getId()],
            'Victory!',
            $scoreline,
            $resultData,
        );
        $this->pushNotificationService->notifyUsers(
            [(string) $loser->getClub()->getUser()->getId()],
            'Eliminated',
            $scoreline,
            $resultData,
        );

        return $winner;
    }

    /**
     * The club copy frozen onto a result.
     *
     * This is now a plain read: the entrant already stores the resolved display block, so
     * there is nothing left to normalise at resolve time. It used to re-run the whole
     * normaliser over the entrant's full snapshot on every fixture.
     *
     * @return array<string, mixed>
     */
    private function clubJsonOf(CompetitionEntrant $entrant): array
    {
        return $entrant->getClubDisplayJson();
    }

    private function breakTie(CompetitionEntrant $home, CompetitionEntrant $away, CompetitionFixture $fixture): CompetitionEntrant
    {
        $rng = new SeededRng(SeededRng::hashId($fixture->getId()->toRfc4122() . ':tiebreak'));

        return $rng->chance(0.5) ? $home : $away;
    }

    private function isFinalRound(CompetitionRound $round): bool
    {
        $labels = BracketLabeler::labelsForCapacity($round->getActiveCompetition()->getEntrantCapacity());

        return $round->getRoundIndex() === count($labels);
    }

    /**
     * Builds or enriches the engine-agnostic computation audit stored on CompetitionResult,
     * ensuring every tournament match result records how it was derived regardless of which
     * MatchEngineInterface resolved the fixture.
     *
     * @return array<string, mixed>
     */
    private function buildComputationAudit(
        CompetitionFixture $fixture,
        CompetitionRound $round,
        CompetitionEntrant $home,
        CompetitionEntrant $away,
        CompetitionEntrant $winner,
        MatchEngineInterface $engine,
        MatchEngineResult $matchResult,
        bool $usedTiebreak,
    ): array {
        $requestedEngine = ($round->getMatchEngineIdentifier() ?? \App\Enum\Competition\MatchEngineIdentifier::DETERMINISTIC)->value;
        $audit           = $matchResult->computationAudit ?? [
            'schemaVersion' => 1,
            'engine'        => [
                'requestedEngine' => $requestedEngine,
                'resolvedBy'      => $requestedEngine,
                'engineClass'     => $engine::class,
                'delegatedFrom'   => null,
                'fallbackReason'  => null,
            ],
            'fixture' => [
                'fixtureId'  => $fixture->getId()->toRfc4122(),
                'rngSeed'    => SeededRng::hashId($fixture->getId()->toRfc4122()),
                'allowDraws' => false,
            ],
            'entrants' => [
                'home' => [
                    'entrantId'       => (string) $home->getId(),
                    'clubId'          => (string) $home->getClub()->getId(),
                    'clubName'        => $home->getClub()->getName(),
                    'seed'            => $home->getSeed(),
                    'snapshotVersion' => $home->getSnapshotVersion(),
                ],
                'away' => [
                    'entrantId'       => (string) $away->getId(),
                    'clubId'          => (string) $away->getClub()->getId(),
                    'clubName'        => $away->getClub()->getName(),
                    'seed'            => $away->getSeed(),
                    'snapshotVersion' => $away->getSnapshotVersion(),
                ],
            ],
        ];

        $audit['engine'] = array_merge($audit['engine'] ?? [], [
            'requestedEngine' => $requestedEngine,
            'engineClass'     => $engine::class,
        ]);

        $audit['round'] = [
            'competitionId' => (string) $round->getActiveCompetition()->getId(),
            'roundId'       => (string) $round->getId(),
            'roundIndex'    => $round->getRoundIndex(),
            'label'         => $round->getLabel(),
            'slotIndex'     => $fixture->getSlotIndex(),
        ];

        $resolutionMethod = $usedTiebreak
            ? 'tiebreak_coin_flip'
            : ($matchResult->wentToPenalties ? 'penalties' : ($matchResult->wentToExtraTime ? 'extra_time' : 'regulation'));

        $audit['outcome'] = array_merge($audit['outcome'] ?? [], [
            'homeScore'        => $matchResult->homeScore,
            'awayScore'        => $matchResult->awayScore,
            'wentToExtraTime'  => $matchResult->wentToExtraTime,
            'wentToPenalties'  => $matchResult->wentToPenalties,
            'penaltyHomeScore' => $matchResult->penaltyHomeScore,
            'penaltyAwayScore' => $matchResult->penaltyAwayScore,
            'usedTiebreak'     => $usedTiebreak,
            'tiebreakSeed'     => $usedTiebreak ? SeededRng::hashId($fixture->getId()->toRfc4122() . ':tiebreak') : null,
            'winnerSide'       => $winner === $home ? 'home' : 'away',
            'winnerEntrantId'  => (string) $winner->getId(),
            'winnerClubId'     => (string) $winner->getClub()->getId(),
            'resolutionMethod' => $resolutionMethod,
        ]);

        return $audit;
    }

    /**
     * Rebuilds this competition's prebuilt bracket response.
     *
     * Called after a pass that changed the bracket, so the next reader finds the document
     * ready instead of paying to assemble it. Strictly an optimisation: the cache validates
     * itself against the competition's state version, so skipping this costs one rebuild on
     * the next read and can never serve a stale bracket.
     *
     * Failures are swallowed deliberately. A tick that published a result has already
     * committed real game state; letting a cache rebuild turn that into a failed tick would
     * be strictly worse than serving the next request from a cold cache.
     */
    private function prewarmResponse(ActiveCompetition $activeCompetition): void
    {
        try {
            $this->responseCache->rebuild(
                $activeCompetition,
                $this->responseBuilder->stateVersion($activeCompetition),
                fn (): array => $this->responseBuilder->sharedDocument($activeCompetition),
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to prewarm the competition response cache.', [
                'competitionId' => (string) $activeCompetition->getId(),
                'exception'     => $e->getMessage(),
            ]);
        }
    }
}
