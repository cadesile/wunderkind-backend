<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionResult;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\Competition\RewardTemplate;
use App\Enum\Appearance\TrophyFinish;
use App\Enum\Appearance\TrophyStyle;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionFixtureStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionResultRepository;
use App\Repository\Competition\CompetitionRoundRepository;

/**
 * Builds the body of GET /api/competitions/{id}.
 *
 * Lifted out of CompetitionController for two reasons. The cron passes that change a bracket
 * need to prebuild this document, and they cannot reach a controller action to do it. And
 * ClubHubController was injecting CompetitionController purely to reuse
 * serializeInstanceSummary() — a controller depending on a controller.
 *
 * The split that matters here is **shared versus per-viewer**. Exactly one key of the
 * response varies by caller: `viewer`. Everything else — the summary, `entrants`, the whole
 * `rounds` bracket — is identical for everyone, which is what makes a single cached document
 * per competition correct rather than a cache keyed per club. sharedDocument() produces that
 * part (carrying `viewer => null`), and the request overwrites `viewer` and `serverTime` on
 * top of it.
 */
class CompetitionResponseBuilder
{
    public function __construct(
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionResultRepository $resultRepository,
        private readonly EligibilityEvaluator $eligibilityEvaluator,
    ) {}

    /**
     * The viewer-independent response body: summary, entrants and the full bracket.
     *
     * `viewer` is present and null. Leaving the key in place means the request path
     * overwrites it rather than appending it, so the key order a client sees does not depend
     * on whether the response came from the cache or was just built.
     *
     * @return array<string, mixed>
     */
    public function sharedDocument(ActiveCompetition $activeCompetition): array
    {
        // Load every entrant once, up front. The rounds loop below reaches through each
        // fixture's home/away association, and those are lazy proxies — so without this the
        // bracket resolved them one SELECT at a time, up to 64 round trips before the
        // entrant listing at the end of this method asked for the same rows again. Doctrine's
        // identity map means that listing is now served from memory.
        $entrants = $this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition);

        $roundEntities     = $this->roundRepository->findByCompetitionOrderedByIndex($activeCompetition);
        $roundsByIndex     = [];
        $fixturesByRoundId = [];
        $allFixtureIds     = [];
        foreach ($roundEntities as $round) {
            $roundsByIndex[$round->getRoundIndex()]          = $round;
            $fixtures                                        = $this->fixtureRepository->findByRoundOrderedBySlot($round);
            $fixturesByRoundId[$round->getId()->toRfc4122()] = $fixtures;
            foreach ($fixtures as $fixture) {
                $allFixtureIds[] = $fixture->getId();
            }
        }

        $resultsByFixtureId = $this->resultRepository->findByFixtureIds($allFixtureIds);
        $capacity           = $activeCompetition->getEntrantCapacity();
        $labels             = BracketLabeler::labelsForCapacity($capacity);

        $rounds = [];
        foreach ($labels as $i => $label) {
            $roundIndex = $i + 1;
            $key        = BracketLabeler::keyForLabel($label);
            $slotCount  = BracketLabeler::slotCountForRound($capacity, $roundIndex);
            $round      = $roundsByIndex[$roundIndex] ?? null;

            if ($round === null) {
                $placeholderFixtures = [];
                for ($slot = 0; $slot < $slotCount; $slot++) {
                    $placeholderFixtures[] = $this->buildTbdFixture($slot);
                }

                $rounds[] = [
                    'roundIndex'       => $roundIndex,
                    'key'              => $key,
                    'label'            => $label,
                    'status'           => 'future',
                    'drawAt'           => null,
                    'scheduledAt'      => null,
                    'startedAt'        => null,
                    'kickoffAt'        => null,
                    'matchesResolveAt' => null,
                    'fixtures'         => $placeholderFixtures,
                ];
                continue;
            }

            $existingFixtures = $fixturesByRoundId[$round->getId()->toRfc4122()] ?? [];
            if ($existingFixtures === []) {
                $fixtures = [];
                for ($slot = 0; $slot < $slotCount; $slot++) {
                    $fixtures[] = $this->buildTbdFixture($slot);
                }
            } else {
                $fixtures = array_map(
                    function (CompetitionFixture $f) use ($resultsByFixtureId): array {
                        $resultEntity  = $resultsByFixtureId[(string) $f->getId()] ?? null;
                        $resultSummary = $this->serializeResultSummary($resultEntity);

                        $score = null;
                        if ($resultEntity !== null) {
                            $score = [
                                'home'      => $resultEntity->getHomeScore(),
                                'away'      => $resultEntity->getAwayScore(),
                                'extraTime' => $resultEntity->isWentToExtraTime(),
                                'penalties' => $resultEntity->isWentToPenalties()
                                    ? ['home' => $resultEntity->getPenaltyHomeScore(), 'away' => $resultEntity->getPenaltyAwayScore()]
                                    : null,
                            ];
                        }

                        return [
                            'fixtureId'       => (string) $f->getId(),
                            'slotIndex'       => $f->getSlotIndex(),
                            'status'          => $f->getStatus()->value,
                            'homeEntrantId'   => $f->getHomeEntrant() !== null ? (string) $f->getHomeEntrant()->getId() : null,
                            'awayEntrantId'   => $f->getAwayEntrant() !== null ? (string) $f->getAwayEntrant()->getId() : null,
                            'winnerEntrantId' => $f->getWinnerEntrant() !== null ? (string) $f->getWinnerEntrant()->getId() : null,
                            'score'           => $score,
                            'matchId'         => $f->getStatus() === CompetitionFixtureStatus::COMPLETE ? (string) $f->getId() : null,
                            'home'            => $this->serializeEntrantSummary($f->getHomeEntrant()),
                            'away'            => $this->serializeEntrantSummary($f->getAwayEntrant()),
                            'result'          => $resultSummary,
                        ];
                    },
                    $existingFixtures,
                );
            }

            // Round 2..N placeholder scheduledAt is endsAt until Round N-1 finishes; only expose drawAt when it is genuinely scheduled.
            $isGenuineDrawAt = $roundIndex === 1
                || $round->getStatus() !== CompetitionRoundStatus::DRAW_PENDING
                || (($roundsByIndex[$roundIndex - 1] ?? null)?->getStatus() === CompetitionRoundStatus::RESULTS_PUBLISHED);
            $drawAtIso    = $isGenuineDrawAt ? $round->getScheduledAt()->format(DATE_ATOM) : $round->getScheduledAt()->format(DATE_ATOM);
            $kickoffAtIso = $round->getMatchesResolveAt()?->format(DATE_ATOM);

            $rounds[] = [
                'roundIndex'       => $round->getRoundIndex(),
                'key'              => $key,
                'label'            => $round->getLabel(),
                'status'           => $round->getStatus()->value,
                'drawAt'           => $drawAtIso,
                'scheduledAt'      => $round->getScheduledAt()->format(DATE_ATOM),
                'startedAt'        => $round->getStartedAt()?->format(DATE_ATOM),
                'kickoffAt'        => $kickoffAtIso,
                'matchesResolveAt' => $kickoffAtIso,
                'fixtures'         => $fixtures,
            ];
        }

        return array_merge($this->serializeInstanceSummary($activeCompetition, null), [
            'entrants' => array_map($this->serializeEntrantPreview(...), $entrants),
            'rounds'   => $rounds,
        ]);
    }

    /**
     * The per-viewer block, composed onto a cached document at request time.
     *
     * Three single-row lookups, against reassembling up to 64 entrants and 63 fixtures.
     */
    public function viewerBlock(ActiveCompetition $activeCompetition, ?Club $viewerClub): ?array
    {
        return $this->buildViewerBlock(
            $activeCompetition,
            $viewerClub,
            $this->roundRepository->findCurrentRound($activeCompetition),
        );
    }

    /**
     * Fingerprint of every piece of state this response depends on — the freshness key for
     * the cached document, and the body of the ETag.
     */
    public function stateFingerprint(ActiveCompetition $activeCompetition): string
    {
        return $this->computeStateFingerprint($activeCompetition);
    }

    /**
     * `$stateFingerprint` is accepted so a caller that already has it — the request path,
     * which needs it for the cache lookup anyway — does not recompute it.
     */
    public function etagFor(ActiveCompetition $activeCompetition, ?Club $club, ?string $stateFingerprint = null): string
    {
        return $this->computeEtag($activeCompetition, $club, $stateFingerprint);
    }


    public function serializeInstanceSummary(ActiveCompetition $instance, ?Club $viewerClub = null): array
    {
        $template        = $instance->getTemplate();
        $currentRound    = $this->roundRepository->findCurrentRound($instance);
        $capacity        = $instance->getEntrantCapacity();
        $registeredCount = $this->entrantRepository->countForCompetition($instance);
        $totalRounds     = (int) round(log($capacity, 2));
        $entryFeePence   = $template->getEntryFeePence();

        $champion = null;
        $runnerUp = null;
        if ($instance->getStatus() === ActiveCompetitionStatus::COMPLETED) {
            $finalFixture = $this->fixtureRepository->findFinalFixtureForCompetition($instance);
            if ($finalFixture !== null && $finalFixture->getWinnerEntrant() !== null) {
                $winner   = $finalFixture->getWinnerEntrant();
                $home     = $finalFixture->getHomeEntrant();
                $away     = $finalFixture->getAwayEntrant();
                $loser    = ($home !== null && $away !== null) ? ($winner === $home ? $away : $home) : null;
                $champion = $this->serializeEntrantSummary($winner);
                $runnerUp = $this->serializeEntrantSummary($loser);
            }
        }

        // Client-facing counter, not the cache key — kept additive so an existing client
        // comparing it numerically is unaffected by the fingerprint change.
        $version = 1 + $registeredCount + count($this->roundRepository->findByCompetitionOrderedByIndex($instance));

        return [
            'instanceId'            => (string) $instance->getId(),
            'templateId'            => (string) $template->getId(),
            'templateName'          => $template->getName(),
            'status'                => $instance->getStatus()->value,
            'registrationOpensAt'   => $instance->getRegistrationOpenedAt()->format(DATE_ATOM),
            'startsAt'              => $instance->getStartsAt()?->format(DATE_ATOM),
            'endsAt'                => $instance->getEndsAt()?->format(DATE_ATOM),
            'completedAt'           => $instance->getCompletedAt()?->format(DATE_ATOM),
            'cancelledAt'           => $instance->getCancelledAt()?->format(DATE_ATOM),
            'cancelReason'          => $instance->getCancellationReason(),
            'trophy'                => $this->normalizeTrophy($template->getTrophy()),
            'entrantCapacity'       => $capacity,
            'registeredCount'       => $registeredCount,
            'slotsRemaining'        => max(0, $capacity - $registeredCount),
            'bracketSize'           => $capacity,
            'byeCount'              => 0,
            'totalRounds'           => $totalRounds,
            'durationOption'        => $instance->getDurationOption()->value,
            'durationHours'         => $instance->getDurationOption()->toHours(),
            'entryConditions'       => $this->serializeEntryConditions($template),
            'entryFeePence'         => $entryFeePence,
            'entryFeePerRound'      => $entryFeePence,
            'entryFeePerRoundPence' => $entryFeePence,
            'prizes'                => $this->serializePrizes($template),
            'champion'              => $champion,
            'runnerUp'              => $runnerUp,
            'nextRoundLabel'        => $currentRound?->getLabel(),
            'nextRoundAt'           => $this->nextRoundDueAt($currentRound),
            'version'               => $version,
            'viewer'                => $this->buildViewerBlock($instance, $viewerClub, $currentRound),
        ];
    }

    private function buildViewerBlock(ActiveCompetition $instance, ?Club $viewerClub, ?CompetitionRound $currentRound): ?array
    {
        if ($viewerClub === null) {
            return null;
        }

        $entrant     = $this->entrantRepository->findByCompetitionAndClub($instance, $viewerClub);
        $relation    = $entrant !== null ? $entrant->getStatus()->value : 'none';
        $eligibility = null;

        if ($instance->getStatus() === ActiveCompetitionStatus::REGISTERING) {
            $eval        = $this->eligibilityEvaluator->evaluate($instance->getTemplate(), $viewerClub);
            $eligibility = [
                'eligible' => $eval->eligible,
                'reasons'  => $eval->structuredReasons,
            ];
        }

        $nextMatchId = null;
        $snapshot    = null;

        if ($entrant !== null && $entrant->getStatus() !== CompetitionEntrantStatus::WITHDRAWN) {
            $pendingFixture = $this->fixtureRepository->findNextPendingFixtureForEntrant($entrant);
            $nextMatchId    = $pendingFixture !== null ? (string) $pendingFixture->getId() : null;

            $locksAt = null;
            if ($currentRound !== null && $currentRound->getStatus() === CompetitionRoundStatus::DRAW_PENDING) {
                $locksAt = $currentRound->getScheduledAt()->format(DATE_ATOM);
            }

            $acceptedAt = $entrant->getClientSnapshotAt() ?? $entrant->getRegisteredAt()->format(DATE_ATOM);
            $snapshot   = [
                'version'    => $entrant->getSnapshotVersion(),
                'acceptedAt' => is_string($acceptedAt) ? $acceptedAt : $entrant->getRegisteredAt()->format(DATE_ATOM),
                'locksAt'    => $locksAt,
            ];
        }

        return [
            'relation'    => $relation,
            'entrantId'   => $entrant !== null && $entrant->getStatus() !== CompetitionEntrantStatus::WITHDRAWN ? (string) $entrant->getId() : null,
            'eligibility' => $eligibility,
            'nextMatchId' => $nextMatchId,
            'snapshot'    => $snapshot,
        ];
    }

    /**
     * Fingerprints every input the cached document reads.
     *
     * This replaced an additive integer (`1 + registeredCount + roundState +
     * snapshotVersionSum`), which failed in two independent ways:
     *
     *  - **It collided.** `countForCompetition()` excludes withdrawn entrants while
     *    `sumSnapshotVersions()` includes them, so a withdrawal netted -1 and another club's
     *    resubmission netted +1. They cancelled, the cached row still matched, and a stale
     *    bracket was served — behind a 304, since the anonymous ETag was also unchanged.
     *  - **It was incomplete.** It omitted the competition's own status, cancellation and
     *    completion, and every template field the document embeds. Cancelling a REGISTERING
     *    competition changes no round and no entrant count, so the body stayed stale
     *    indefinitely; a template edit never reached a running competition at all.
     *
     * Every component below is therefore something the document actually renders. Counts are
     * kept as separate labelled components rather than summed, so no two different states can
     * produce the same string.
     */
    private function computeStateFingerprint(ActiveCompetition $instance): string
    {
        $template = $instance->getTemplate();

        $roundStates = [];
        foreach ($this->roundRepository->findByCompetitionOrderedByIndex($instance) as $r) {
            $roundStates[] = $r->getRoundIndex() . ':' . $r->getStatus()->value
                . ':' . ($r->getScheduledAt()?->getTimestamp() ?? 0)
                . ':' . ($r->getMatchesResolveAt()?->getTimestamp() ?? 0)
                . ':' . ($r->getStartedAt()?->getTimestamp() ?? 0);
        }

        // Template fields are hashed rather than versioned because CompetitionTemplate
        // carries no updatedAt: what matters is that the values the document embeds are the
        // ones it was built from.
        $templatePart = json_encode([
            'name'              => $template->getName(),
            'trophy'            => $template->getTrophy(),
            'entrantCapacity'   => $instance->getEntrantCapacity(),
            'entryFeePence'     => $template->getEntryFeePence(),
            'victorPrize'       => $template->getVictorPrize(),
            'minClubReputation' => $template->getMinClubReputation(),
            'minClubAgeSeasons' => $template->getMinClubAgeSeasons(),
            'allowedTiers'      => $template->getAllowedTiers(),
            'rewards'           => array_map(
                static fn (RewardTemplate $reward) => [$reward->getSlug(), $reward->getName(), $reward->getDescription(), $reward->getEffects()],
                $template->getRewardTemplates()->toArray(),
            ),
        ], JSON_THROW_ON_ERROR);

        $components = [
            'competition'      => (string) $instance->getId(),
            'status'           => $instance->getStatus()->value,
            'startsAt'         => $instance->getStartsAt()?->getTimestamp() ?? 0,
            'endsAt'           => $instance->getEndsAt()?->getTimestamp() ?? 0,
            'completedAt'      => $instance->getCompletedAt()?->getTimestamp() ?? 0,
            'cancelledAt'      => $instance->getCancelledAt()?->getTimestamp() ?? 0,
            'cancelReason'     => $instance->getCancellationReason(),
            'activeEntrants'   => $this->entrantRepository->countForCompetition($instance),
            'snapshotVersions' => $this->entrantRepository->sumSnapshotVersions($instance),
            'entrantRows'      => $this->entrantRepository->countAllForCompetition($instance),
            'rounds'           => $roundStates,
            'template'         => $templatePart,
        ];

        return substr(hash('sha256', json_encode($components, JSON_THROW_ON_ERROR)), 0, 32);
    }

    private function computeEtag(ActiveCompetition $instance, ?Club $club, ?string $stateFingerprint = null): string
    {
        $version = $stateFingerprint ?? $this->stateFingerprint($instance);
        $clubPart        = $club !== null ? (string) $club->getId() : 'anon';
        $entrantVersion  = 0;
        if ($club !== null) {
            $entrant        = $this->entrantRepository->findByCompetitionAndClub($instance, $club);
            $entrantVersion = $entrant !== null ? $entrant->getSnapshotVersion() : 0;
        }

        return sprintf('W/"%s-%s-%d-%s-%d"', $instance->getId(), $instance->getStatus()->value, $version, $clubPart, $entrantVersion);
    }

    public function serializeEntrantSummary(?CompetitionEntrant $entrant): ?array
    {
        if ($entrant === null) {
            return null;
        }

        // Straight off the stored display block. This used to re-run the full club
        // normaliser over the entrant's whole snapshot, once per entrant AND twice more per
        // fixture, which is how a 64-club bracket came to decode every squad in it several
        // times over in a single request.
        $normalized = $entrant->getClubDisplayJson();

        $summary = [
            'entrantId'      => (string) $entrant->getId(),
            'clubId'         => $normalized['clubId'],
            'clubName'       => $normalized['clubName'],
            'name'           => $normalized['name'],
            'abbreviation'   => $normalized['abbreviation'],
            'country'        => $normalized['country'],
            'ovr'            => $normalized['ovr'],
            'reputation'     => $normalized['reputation'],
            'reputationTier' => $normalized['reputationTier'],
            'tier'           => $normalized['tier'],
            'seed'           => $entrant->getSeed(),
            'homeKitConfig'  => $normalized['homeKitConfig'],
            'awayKitConfig'  => $normalized['awayKitConfig'],
            'badgeConfig'    => $normalized['badgeConfig'],
            'identity'       => $normalized['identity'],
            'stadium'        => $normalized['stadium'],
            'badgeShape'     => $normalized['badgeShape'],
            'homePrimary'    => $normalized['homePrimary'],
            'homeSecondary'  => $normalized['homeSecondary'],
            'awayPrimary'    => $normalized['awayPrimary'],
            'awaySecondary'  => $normalized['awaySecondary'],
            'formation'      => $normalized['formation'] ?? null,
        ];

        if (isset($normalized['stadiumName'])) {
            $summary['stadiumName'] = $normalized['stadiumName'];
        }
        if (isset($normalized['playingStyle'])) {
            $summary['playingStyle'] = $normalized['playingStyle'];
        }

        return $summary;
    }

    /** Pre-bracket entrant listing (used before rounds/fixtures exist) — same club display fields as serializeEntrantSummary(), plus registration metadata. */
    public function serializeEntrantPreview(CompetitionEntrant $entrant): array
    {
        return [
            ...$this->serializeEntrantSummary($entrant),
            'registeredAt' => $entrant->getRegisteredAt()->format(DATE_ATOM),
            'status'       => $entrant->getStatus()->value,
        ];
    }

    private function serializeResultSummary(?CompetitionResult $result): ?array
    {
        return $result?->toClientSummary();
    }

    private function buildTbdFixture(int $slotIndex): array
    {
        return [
            'fixtureId'       => null,
            'slotIndex'       => $slotIndex,
            'status'          => 'tbd',
            'homeEntrantId'   => null,
            'awayEntrantId'   => null,
            'winnerEntrantId' => null,
            'score'           => null,
            'matchId'         => null,
            'home'            => null,
            'away'            => null,
            'result'          => null,
        ];
    }

    private function nextRoundDueAt(?CompetitionRound $currentRound): ?string
    {
        if ($currentRound === null) {
            return null;
        }

        $dueAt = $currentRound->getStatus() === CompetitionRoundStatus::DRAWN
            ? $currentRound->getMatchesResolveAt()
            : $currentRound->getScheduledAt();

        return $dueAt?->format(DATE_ATOM);
    }

    public function normalizeTrophy(?array $trophy): ?array
    {
        if ($trophy === null) {
            return null;
        }

        $styleRaw  = (string) ($trophy['style'] ?? 'classic');
        $finishRaw = (string) ($trophy['finish'] ?? 'gold');

        $style  = TrophyStyle::tryFrom($styleRaw)?->value ?? TrophyStyle::CLASSIC->value;
        $finish = TrophyFinish::tryFrom($finishRaw)?->value ?? TrophyFinish::GOLD->value;

        return [
            'style'           => $style,
            'finish'          => $finish,
            'ribbons'         => (bool) ($trophy['ribbons'] ?? false),
            'ribbonPrimary'   => is_string($trophy['ribbonPrimary'] ?? null) ? $trophy['ribbonPrimary'] : '#c8202f',
            'ribbonSecondary' => is_string($trophy['ribbonSecondary'] ?? null) ? $trophy['ribbonSecondary'] : '#f4f3ee',
        ];
    }

    private function serializePrizes(CompetitionTemplate $template): array
    {
        $victorPrize = $template->getVictorPrize();

        return [
            'victorPrize'      => $victorPrize,
            'victorPrizePence' => $victorPrize,
            'bonusRewards'     => array_map(
                static fn (RewardTemplate $reward) => [
                    'slug'        => $reward->getSlug(),
                    'name'        => $reward->getName(),
                    'description' => $reward->getDescription(),
                    'effects'     => $reward->getEffects(),
                ],
                $template->getRewardTemplates()->toArray(),
            ),
        ];
    }

    private function serializeEntryConditions(CompetitionTemplate $template): array
    {
        return [
            'minClubReputation' => $template->getMinClubReputation(),
            'minClubAgeSeasons' => $template->getMinClubAgeSeasons(),
            'allowedTiers'      => $template->getAllowedTiers(),
        ];
    }
}
