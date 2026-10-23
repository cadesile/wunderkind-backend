<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionResult;
use App\Entity\Friendly\FriendlyChallenge;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionFixtureStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Enum\Friendly\FriendlyChallengeStatus;
use App\Enum\Tier;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionResultRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Repository\Friendly\FriendlyChallengeRepository;
use App\Service\ClubInitializationService;
use Symfony\Component\Uid\Uuid;

class MatchQueryService
{
    public function __construct(
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionResultRepository $resultRepository,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly FriendlyChallengeRepository $friendlyRepository,
    ) {}

    /**
     * Resolves a first-class Match detail payload for either a Cup fixture or a Friendly challenge.
     *
     * @return array<string, mixed>|null
     */
    public function findMatchDetail(string $matchId): ?array
    {
        if (!Uuid::isValid($matchId)) {
            return null;
        }

        $fixture = $this->fixtureRepository->find($matchId);
        if ($fixture instanceof CompetitionFixture) {
            return $this->serializeCupMatchDetail($fixture);
        }

        $challenge = $this->friendlyRepository->find($matchId);
        if ($challenge instanceof FriendlyChallenge && in_array($challenge->getStatus(), [FriendlyChallengeStatus::ACCEPTED, FriendlyChallengeStatus::COMPLETE], true)) {
            return $this->serializeFriendlyMatchDetail($challenge);
        }

        return null;
    }

    /**
     * Returns a club's upcoming matches across all active tournaments and accepted friendlies.
     *
     * @return list<array<string, mixed>>
     */
    public function findUpcomingMatchesForClub(Club $club): array
    {
        $items    = [];
        $entrants = $this->entrantRepository->findByClub($club, includeWithdrawn: false);

        foreach ($entrants as $entrant) {
            $competition = $entrant->getActiveCompetition();
            $compStatus  = $competition->getStatus();

            if (!in_array($compStatus, [ActiveCompetitionStatus::REGISTERING, ActiveCompetitionStatus::SCHEDULED, ActiveCompetitionStatus::RUNNING], true)) {
                continue;
            }
            if (!in_array($entrant->getStatus(), [CompetitionEntrantStatus::REGISTERED, CompetitionEntrantStatus::ACTIVE], true)) {
                continue;
            }

            if ($compStatus === ActiveCompetitionStatus::REGISTERING) {
                $labels          = BracketLabeler::labelsForCapacity($competition->getEntrantCapacity());
                $round1Label     = $labels[0];
                $registeredCount = $this->entrantRepository->countForCompetition($competition);

                $items[] = [
                    'matchId'     => null,
                    'kind'        => 'cup',
                    'status'      => 'awaiting_entrants',
                    'drawAt'      => null,
                    'kickoffAt'   => null,
                    'competition' => [
                        'instanceId'      => (string) $competition->getId(),
                        'templateId'      => (string) $competition->getTemplate()->getId(),
                        'templateName'    => $competition->getTemplate()->getName(),
                        'roundIndex'      => 1,
                        'roundKey'        => BracketLabeler::keyForLabel($round1Label),
                        'roundLabel'      => $round1Label,
                        'registeredCount' => $registeredCount,
                        'entrantCapacity' => $competition->getEntrantCapacity(),
                    ],
                    'friendly' => null,
                    'home'     => $this->serializeClubFromEntrant($entrant),
                    'away'     => null,
                    'score'    => null,
                    'h2h'      => null,
                ];
                continue;
            }

            $currentRound = $this->roundRepository->findCurrentRound($competition);
            if ($currentRound === null) {
                continue;
            }

            if ($currentRound->getStatus() === CompetitionRoundStatus::DRAW_PENDING) {
                $items[] = [
                    'matchId'     => null,
                    'kind'        => 'cup',
                    'status'      => 'awaiting_draw',
                    'drawAt'      => $currentRound->getScheduledAt()->format(DATE_ATOM),
                    'kickoffAt'   => $currentRound->getMatchesResolveAt()?->format(DATE_ATOM),
                    'competition' => [
                        'instanceId'   => (string) $competition->getId(),
                        'templateId'   => (string) $competition->getTemplate()->getId(),
                        'templateName' => $competition->getTemplate()->getName(),
                        'roundIndex'   => $currentRound->getRoundIndex(),
                        'roundKey'     => BracketLabeler::keyForLabel($currentRound->getLabel()),
                        'roundLabel'   => $currentRound->getLabel(),
                    ],
                    'friendly' => null,
                    'home'     => $this->serializeClubFromEntrant($entrant),
                    'away'     => null,
                    'score'    => null,
                    'h2h'      => null,
                ];
                continue;
            }

            $pendingFixture = $this->fixtureRepository->findNextPendingFixtureForEntrant($entrant);
            if ($pendingFixture !== null) {
                $homeEntrant = $pendingFixture->getHomeEntrant();
                $awayEntrant = $pendingFixture->getAwayEntrant();
                $opponent    = ($homeEntrant !== null && $homeEntrant->getId()->equals($entrant->getId())) ? $awayEntrant : $homeEntrant;
                $h2h         = $opponent !== null ? $this->computeH2hCounts((string) $club->getId(), (string) $opponent->getClub()->getId()) : null;

                $items[] = [
                    'matchId'     => (string) $pendingFixture->getId(),
                    'kind'        => 'cup',
                    'status'      => 'scheduled',
                    'drawAt'      => $currentRound->getScheduledAt()->format(DATE_ATOM),
                    'kickoffAt'   => $currentRound->getMatchesResolveAt()?->format(DATE_ATOM),
                    'competition' => [
                        'instanceId'   => (string) $competition->getId(),
                        'templateId'   => (string) $competition->getTemplate()->getId(),
                        'templateName' => $competition->getTemplate()->getName(),
                        'roundIndex'   => $currentRound->getRoundIndex(),
                        'roundKey'     => BracketLabeler::keyForLabel($currentRound->getLabel()),
                        'roundLabel'   => $currentRound->getLabel(),
                    ],
                    'friendly' => null,
                    'home'     => $homeEntrant !== null ? $this->serializeClubFromEntrant($homeEntrant) : null,
                    'away'     => $awayEntrant !== null ? $this->serializeClubFromEntrant($awayEntrant) : null,
                    'score'    => null,
                    'h2h'      => $h2h,
                ];
            }
        }

        foreach ($this->friendlyRepository->findAcceptedForClub($club) as $challenge) {
            $items[] = $this->serializeFriendlyMatchSummary($challenge, $club);
        }

        usort($items, static function (array $a, array $b): int {
            $priority = ['scheduled' => 0, 'awaiting_draw' => 1, 'awaiting_entrants' => 2];
            $pa       = $priority[$a['status']] ?? 3;
            $pb       = $priority[$b['status']] ?? 3;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            $timeA = $a['kickoffAt'] ?? $a['drawAt'] ?? '9999-12-31T23:59:59+00:00';
            $timeB = $b['kickoffAt'] ?? $b['drawAt'] ?? '9999-12-31T23:59:59+00:00';

            return strcmp((string) $timeA, (string) $timeB);
        });

        return $items;
    }

    /**
     * Returns a club's recent completed matches across tournaments and friendlies.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentMatchesForClub(Club $club, int $limit = 20): array
    {
        $items    = [];
        $fixtures = $this->fixtureRepository->findCompletedForClub($club, $limit);
        $fIds     = array_map(static fn (CompetitionFixture $f) => $f->getId(), $fixtures);
        $results  = $this->resultRepository->findByFixtureIds($fIds);

        foreach ($fixtures as $fixture) {
            $resultEntity = $results[(string) $fixture->getId()] ?? null;
            $items[]      = $this->serializeCupMatchSummary($fixture, $resultEntity, $club);
        }

        foreach ($this->friendlyRepository->findCompletedForClub($club, $limit) as $challenge) {
            $items[] = $this->serializeFriendlyMatchSummary($challenge, $club);
        }

        usort($items, static fn (array $a, array $b) => strcmp((string) ($b['kickoffAt'] ?? ''), (string) ($a['kickoffAt'] ?? '')));

        return array_slice($items, 0, $limit);
    }

    /**
     * Computes head-to-head tally + recent matches between $club and $opponentClubId.
     *
     * @return array{won: int, drawn: int, lost: int, recent: list<array<string, mixed>>}
     */
    public function getHeadToHead(Club $club, string $opponentClubId, int $recentLimit = 10): array
    {
        $clubId = (string) $club->getId();
        $won    = 0;
        $drawn  = 0;
        $lost   = 0;
        $recent = [];

        $cupFixtures = $this->fixtureRepository->findCompletedBetweenClubs($clubId, $opponentClubId);
        $fIds        = array_map(static fn (CompetitionFixture $f) => $f->getId(), $cupFixtures);
        $results     = $this->resultRepository->findByFixtureIds($fIds);

        foreach ($cupFixtures as $fixture) {
            $winner = $fixture->getWinnerEntrant();
            if ($winner !== null) {
                if ((string) $winner->getClub()->getId() === $clubId) {
                    $won++;
                } else {
                    $lost++;
                }
            }
            $recent[] = $this->serializeCupMatchSummary($fixture, $results[(string) $fixture->getId()] ?? null, $club);
        }

        $friendlies = $this->friendlyRepository->findCompletedBetweenClubs($clubId, $opponentClubId);
        foreach ($friendlies as $challenge) {
            $homeScore   = (int) $challenge->getHomeScore();
            $awayScore   = (int) $challenge->getAwayScore();
            $homeClubId  = (string) ($challenge->getHomeClubJson()['clubId'] ?? '');
            $callerHome  = $homeClubId === $clubId;
            $callerGoals = $callerHome ? $homeScore : $awayScore;
            $oppGoals    = $callerHome ? $awayScore : $homeScore;

            if ($callerGoals > $oppGoals) {
                $won++;
            } elseif ($callerGoals < $oppGoals) {
                $lost++;
            } else {
                $drawn++;
            }

            $recent[] = $this->serializeFriendlyMatchSummary($challenge, $club);
        }

        usort($recent, static fn (array $a, array $b) => strcmp((string) ($b['kickoffAt'] ?? ''), (string) ($a['kickoffAt'] ?? '')));

        return [
            'won'    => $won,
            'drawn'  => $drawn,
            'lost'   => $lost,
            'recent' => array_slice($recent, 0, $recentLimit),
        ];
    }

    /**
     * @return array{won: int, drawn: int, lost: int}
     */
    public function computeH2hCounts(string $clubId, string $opponentClubId): array
    {
        $won   = 0;
        $drawn = 0;
        $lost  = 0;

        foreach ($this->fixtureRepository->findCompletedBetweenClubs($clubId, $opponentClubId) as $fixture) {
            $winner = $fixture->getWinnerEntrant();
            if ($winner !== null) {
                if ((string) $winner->getClub()->getId() === $clubId) {
                    $won++;
                } else {
                    $lost++;
                }
            }
        }

        foreach ($this->friendlyRepository->findCompletedBetweenClubs($clubId, $opponentClubId) as $challenge) {
            $homeScore   = (int) $challenge->getHomeScore();
            $awayScore   = (int) $challenge->getAwayScore();
            $homeClubId  = (string) ($challenge->getHomeClubJson()['clubId'] ?? '');
            $callerHome  = $homeClubId === $clubId;
            $callerGoals = $callerHome ? $homeScore : $awayScore;
            $oppGoals    = $callerHome ? $awayScore : $homeScore;

            if ($callerGoals > $oppGoals) {
                $won++;
            } elseif ($callerGoals < $oppGoals) {
                $lost++;
            } else {
                $drawn++;
            }
        }

        return ['won' => $won, 'drawn' => $drawn, 'lost' => $lost];
    }

    /** @return array<string, mixed> */
    public function serializeCupMatchDetail(CompetitionFixture $fixture): array
    {
        $round        = $fixture->getRound();
        $competition  = $round->getActiveCompetition();
        $resultEntity = $this->resultRepository->findOneBy(['fixture' => $fixture]);

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

        $homeEntrant = $fixture->getHomeEntrant();
        $awayEntrant = $fixture->getAwayEntrant();

        $homeClub = $resultEntity !== null
            ? $this->normalizeClubIdentityArray($resultEntity->getHomeClubJson(), $homeEntrant?->getClub())
            : ($homeEntrant !== null ? $this->serializeClubFromEntrant($homeEntrant) : null);

        $awayClub = $resultEntity !== null
            ? $this->normalizeClubIdentityArray($resultEntity->getAwayClubJson(), $awayEntrant?->getClub())
            : ($awayEntrant !== null ? $this->serializeClubFromEntrant($awayEntrant) : null);

        $stripPlayerId = static function (array $p): array {
            unset($p['id']);

            return $p;
        };

        $kickoffAt = ($fixture->getProcessedAt() ?? $round->getMatchesResolveAt() ?? $round->getScheduledAt())->format(DATE_ATOM);

        return [
            'matchId'     => (string) $fixture->getId(),
            'kind'        => 'cup',
            'competition' => [
                'instanceId'   => (string) $competition->getId(),
                'templateId'   => (string) $competition->getTemplate()->getId(),
                'templateName' => $competition->getTemplate()->getName(),
                'roundIndex'   => $round->getRoundIndex(),
                'roundKey'     => BracketLabeler::keyForLabel($round->getLabel()),
                'roundLabel'   => $round->getLabel(),
            ],
            'friendly'         => null,
            'drawAt'           => $round->getScheduledAt()->format(DATE_ATOM),
            'kickoffAt'        => $kickoffAt,
            'status'           => $fixture->getStatus() === CompetitionFixtureStatus::COMPLETE ? 'complete' : 'scheduled',
            'home'             => $homeClub,
            'away'             => $awayClub,
            'score'            => $score,
            'homeLineup'       => $resultEntity !== null ? array_map($stripPlayerId, $resultEntity->getHomeLineupJson()) : [],
            'awayLineup'       => $resultEntity !== null ? array_map($stripPlayerId, $resultEntity->getAwayLineupJson()) : [],
            'narrativePayload' => $resultEntity?->getNarrativePayload() ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function serializeFriendlyMatchDetail(FriendlyChallenge $challenge): array
    {
        $isComplete  = $challenge->getStatus() === FriendlyChallengeStatus::COMPLETE;
        $homeIsChall = $challenge->getVenue() === 'home';

        $challengerIdentity = $this->serializeClubFromSnapshot($challenge->getChallengerSnapshotJson(), $challenge->getChallengerClub(), $challenge->getChallengerClubId());
        $acceptorIdentity   = $challenge->getAcceptorSnapshotJson() !== null
            ? $this->serializeClubFromSnapshot($challenge->getAcceptorSnapshotJson(), $challenge->getAcceptorClub(), (string) $challenge->getAcceptorClubId())
            : null;

        $homeClub = $isComplete && $challenge->getHomeClubJson() !== null
            ? $this->normalizeClubIdentityArray($challenge->getHomeClubJson(), $homeIsChall ? $challenge->getChallengerClub() : $challenge->getAcceptorClub())
            : ($homeIsChall ? $challengerIdentity : $acceptorIdentity);

        $awayClub = $isComplete && $challenge->getAwayClubJson() !== null
            ? $this->normalizeClubIdentityArray($challenge->getAwayClubJson(), $homeIsChall ? $challenge->getAcceptorClub() : $challenge->getChallengerClub())
            : ($homeIsChall ? $acceptorIdentity : $challengerIdentity);

        $stripPlayerId = static function (array $p): array {
            unset($p['id']);

            return $p;
        };

        $score = $isComplete ? [
            'home'      => $challenge->getHomeScore(),
            'away'      => $challenge->getAwayScore(),
            'extraTime' => false,
            'penalties' => null,
        ] : null;

        $kickoffAt = ($challenge->getResolvedAt() ?? $challenge->getKickoffAt() ?? $challenge->getCreatedAt())->format(DATE_ATOM);

        return [
            'matchId'     => (string) $challenge->getId(),
            'kind'        => 'friendly',
            'competition' => null,
            'friendly'    => [
                'challengeId' => (string) $challenge->getId(),
                'stakePence'  => $challenge->getStakePence(),
                'venue'       => $challenge->getVenue(),
            ],
            'drawAt'           => null,
            'kickoffAt'        => $kickoffAt,
            'status'           => $isComplete ? 'complete' : 'scheduled',
            'home'             => $homeClub,
            'away'             => $awayClub,
            'score'            => $score,
            'homeLineup'       => $challenge->getHomeLineupJson() !== null ? array_map($stripPlayerId, $challenge->getHomeLineupJson()) : [],
            'awayLineup'       => $challenge->getAwayLineupJson() !== null ? array_map($stripPlayerId, $challenge->getAwayLineupJson()) : [],
            'narrativePayload' => $challenge->getNarrativePayload() ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function serializeCupMatchSummary(CompetitionFixture $fixture, ?CompetitionResult $resultEntity, ?Club $viewerClub = null): array
    {
        $round       = $fixture->getRound();
        $competition = $round->getActiveCompetition();
        $homeEntrant = $fixture->getHomeEntrant();
        $awayEntrant = $fixture->getAwayEntrant();

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

        $homeClub = $resultEntity !== null
            ? $this->normalizeClubIdentityArray($resultEntity->getHomeClubJson(), $homeEntrant?->getClub())
            : ($homeEntrant !== null ? $this->serializeClubFromEntrant($homeEntrant) : null);

        $awayClub = $resultEntity !== null
            ? $this->normalizeClubIdentityArray($resultEntity->getAwayClubJson(), $awayEntrant?->getClub())
            : ($awayEntrant !== null ? $this->serializeClubFromEntrant($awayEntrant) : null);

        $h2h = null;
        if ($viewerClub !== null && $homeClub !== null && $awayClub !== null) {
            $viewerId   = (string) $viewerClub->getId();
            $opponentId = ($homeClub['clubId'] ?? '') === $viewerId ? ($awayClub['clubId'] ?? null) : ($homeClub['clubId'] ?? null);
            if (is_string($opponentId) && $opponentId !== '') {
                $h2h = $this->computeH2hCounts($viewerId, $opponentId);
            }
        }

        $kickoffAt = ($fixture->getProcessedAt() ?? $round->getMatchesResolveAt() ?? $round->getScheduledAt())->format(DATE_ATOM);

        return [
            'matchId'     => (string) $fixture->getId(),
            'kind'        => 'cup',
            'status'      => $fixture->getStatus() === CompetitionFixtureStatus::COMPLETE ? 'complete' : 'scheduled',
            'drawAt'      => $round->getScheduledAt()->format(DATE_ATOM),
            'kickoffAt'   => $kickoffAt,
            'competition' => [
                'instanceId'   => (string) $competition->getId(),
                'templateId'   => (string) $competition->getTemplate()->getId(),
                'templateName' => $competition->getTemplate()->getName(),
                'roundIndex'   => $round->getRoundIndex(),
                'roundKey'     => BracketLabeler::keyForLabel($round->getLabel()),
                'roundLabel'   => $round->getLabel(),
            ],
            'friendly' => null,
            'home'     => $homeClub,
            'away'     => $awayClub,
            'score'    => $score,
            'h2h'      => $h2h,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeFriendlyMatchSummary(FriendlyChallenge $challenge, ?Club $viewerClub = null): array
    {
        $detail = $this->serializeFriendlyMatchDetail($challenge);
        $h2h    = null;
        if ($viewerClub !== null && $detail['home'] !== null && $detail['away'] !== null) {
            $viewerId   = (string) $viewerClub->getId();
            $opponentId = ($detail['home']['clubId'] ?? '') === $viewerId ? ($detail['away']['clubId'] ?? null) : ($detail['home']['clubId'] ?? null);
            if (is_string($opponentId) && $opponentId !== '') {
                $h2h = $this->computeH2hCounts($viewerId, $opponentId);
            }
        }

        return [
            'matchId'     => $detail['matchId'],
            'kind'        => 'friendly',
            'status'      => $detail['status'],
            'drawAt'      => null,
            'kickoffAt'   => $detail['kickoffAt'],
            'competition' => null,
            'friendly'    => $detail['friendly'],
            'home'        => $detail['home'],
            'away'        => $detail['away'],
            'score'       => $detail['score'],
            'h2h'         => $h2h,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeClubFromEntrant(CompetitionEntrant $entrant): array
    {
        return $this->serializeClubFromSnapshot($entrant->getSnapshotJson(), $entrant->getClub(), (string) $entrant->getClub()->getId());
    }

    /**
     * @param array<string, mixed> $snapshotJson
     * @return array<string, mixed>
     */
    public function serializeClubFromSnapshot(array $snapshotJson, ?Club $clubEntity, string $fallbackClubId): array
    {
        $snapshotClub = is_array($snapshotJson['club'] ?? null) ? $snapshotJson['club'] : [];
        $ovr          = is_numeric($snapshotClub['ovr'] ?? null)
            ? (int) $snapshotClub['ovr']
            : CompetitionRegistrationService::computeStartingXiOvr($snapshotJson);

        $snapshotClub['ovr'] = $ovr;
        if (!isset($snapshotClub['clubId'])) {
            $snapshotClub['clubId'] = $fallbackClubId;
        }

        return $this->normalizeClubIdentityArray($snapshotClub, $clubEntity);
    }

    /**
     * @param array<string, mixed> $clubData
     * @return array<string, mixed>
     */
    public function normalizeClubIdentityArray(array $clubData, ?Club $clubEntity = null): array
    {
        $clubId       = (string) ($clubData['clubId'] ?? $clubData['id'] ?? ($clubEntity !== null ? (string) $clubEntity->getId() : ''));
        $name         = (string) ($clubData['name'] ?? $clubData['clubName'] ?? $clubEntity?->getName() ?? 'Unknown FC');
        $abbreviation = (string) ($clubData['abbreviation'] ?? $clubEntity?->getAbbreviation() ?? ClubInitializationService::generateAbbreviation($name));
        $reputation   = is_numeric($clubData['reputation'] ?? null)
            ? (int) $clubData['reputation']
            : ($clubEntity?->getReputation() ?? 0);
        $ovr          = is_numeric($clubData['ovr'] ?? null) ? (int) $clubData['ovr'] : 50;

        $badgeConfig = $clubData['badgeConfig'] ?? $clubEntity?->getBadgeConfig();
        if (is_array($badgeConfig) && in_array($badgeConfig['badgeShape'] ?? null, ['circle', 'badge'], true)) {
            $badgeConfig['badgeShape'] = 'round';
        }

        return [
            'clubId'         => $clubId,
            'clubName'       => $name,
            'name'           => $name,
            'abbreviation'   => $abbreviation,
            'country'        => $clubData['country'] ?? $clubEntity?->getCountry(),
            'ovr'            => $ovr,
            'reputation'     => $reputation,
            'reputationTier' => Tier::fromScore($reputation)->value,
            'homeKitConfig'  => $clubData['homeKitConfig'] ?? $clubEntity?->getHomeKitConfig(),
            'awayKitConfig'  => $clubData['awayKitConfig'] ?? $clubEntity?->getAwayKitConfig(),
            'badgeConfig'    => $badgeConfig,
        ];
    }
}
