<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionResult;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Landing-page Cup Results board (#cup-results): the latest completed cup in full,
 * plus up to EARLIER_LIMIT earlier finals.
 *
 * Reads ONLY frozen data for club identity — CompetitionResult::$homeClubJson /
 * $awayClubJson, copied at the final whistle — so a deleted or rebranded club renders
 * as it was on the day, matching the page's Period Awards rule.
 *
 * Returns null when nothing has completed, which hides both the section and its nav
 * link (the same emptiness gate _period_awards uses).
 *
 * Query budget: 1 (recent completed) + per cup: rounds + fixtures/results for the
 * latest; final only for earlier cups. Bounded by EARLIER_LIMIT + 6 rounds.
 *
 * TODO(owner/manager): owner and manager appearances are NOT part of the frozen club
 * display block, and resolveOwner()/resolveManager() are stubs returning null — both
 * figures render with an empty sprite and an em dash. Wiring them live is also why they
 * take a NULLABLE Club: a deleted club nulls `competition_entrant.club_id` (ON DELETE
 * SET NULL, see BracketSurvivesClubDeletionTest) while the final still renders from the
 * frozen block, so there is no Club left to read. If they must be frozen too, add
 * `ownerName/ownerAppearance/managerName/managerAppearance` to
 * CompetitionRegistrationService::clubDisplayBlock() instead.
 */
final class CupResultsBoardService
{
    public const EARLIER_LIMIT = 4;

    public function __construct(
        private readonly ActiveCompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return array{latest: array<string, mixed>, earlier: list<array<string, mixed>>}|null */
    public function getBoard(): ?array
    {
        // ...WithKnownCompletion, not findRecentlyCompleted: an instance marked COMPLETED with
        // no completedAt sorts FIRST under Postgres's `DESC` (NULLS FIRST) and would be
        // presented here as the latest cup final. See that method for why it is its own query.
        $completed = $this->competitionRepository->findRecentlyCompletedWithKnownCompletion(self::EARLIER_LIMIT + 1);

        $cups = [];
        foreach ($completed as $competition) {
            $final = $this->finalOf($competition);
            if ($final !== null) {
                $cups[] = [$competition, $final];
            }
        }

        if ($cups === []) {
            return null;
        }

        [$latestCompetition, $latestFinal] = array_shift($cups);

        return [
            'latest'  => $this->buildLatest($latestCompetition, $latestFinal),
            'earlier' => array_map(fn (array $c) => $this->buildSummary($c[0], $c[1]), $cups),
        ];
    }

    /** @return array{fixture: CompetitionFixture, result: CompetitionResult}|null */
    private function finalOf(ActiveCompetition $competition): ?array
    {
        $rounds = $this->roundRepository->findByCompetitionOrderedByIndex($competition);
        $last = end($rounds);
        if ($last === false) {
            return null;
        }

        $fixture = $this->em->getRepository(CompetitionFixture::class)->findOneBy(['round' => $last, 'slotIndex' => 0]);
        $result  = $fixture ? $this->em->getRepository(CompetitionResult::class)->findOneBy(['fixture' => $fixture]) : null;

        return ($fixture && $result && $fixture->getWinnerEntrant()) ? ['fixture' => $fixture, 'result' => $result] : null;
    }

    /** @param array{fixture: CompetitionFixture, result: CompetitionResult} $final */
    private function buildSummary(ActiveCompetition $competition, array $final): array
    {
        $template = $competition->getTemplate();
        [$winner, $runnerUp] = $this->sides($final['fixture'], $final['result']);

        return [
            'name'             => $template->getName(),
            'completedAt'      => $competition->getCompletedAt(),
            'capacity'         => $competition->getEntrantCapacity(),
            'prizePence'       => $template->getVictorPrize(),
            'trophy'           => $template->getTrophy(),
            'winner'           => $winner,
            'runnerUp'         => $runnerUp,
            'wentToExtraTime'  => $final['result']->isWentToExtraTime(),
            'wentToPenalties'  => $final['result']->isWentToPenalties(),
        ];
    }

    /** @param array{fixture: CompetitionFixture, result: CompetitionResult} $final */
    private function buildLatest(ActiveCompetition $competition, array $final): array
    {
        $summary = $this->buildSummary($competition, $final);
        $result  = $final['result'];
        $winnerEntrant = $final['fixture']->getWinnerEntrant();
        $winnerIsHome  = $this->isHome($final['fixture'], $winnerEntrant);

        [$penW, $penL] = $winnerIsHome
            ? [$result->getPenaltyHomeScore(), $result->getPenaltyAwayScore()]
            : [$result->getPenaltyAwayScore(), $result->getPenaltyHomeScore()];

        $club    = $winnerEntrant->getClub();
        $owner   = $this->resolveOwner($club);
        $manager = $this->resolveManager($club);
        $potf    = $this->playerOfTheFinal($result, $winnerIsHome, $summary['winner']['kit']);

        $summary['penaltyWinner'] = $penW;
        $summary['penaltyLoser']  = $penL;
        $summary['road']          = $this->roadToFinal($competition, $winnerEntrant);
        $summary['figures']       = [
            ['kind' => 'owner',   'role' => 'OWNER',           'name' => $owner['name'] ?? '—',   'stat' => null,                     'sprite' => $owner['appearance'] ?? null],
            ['kind' => 'manager', 'role' => 'MANAGER',         'name' => $manager['name'] ?? '—', 'stat' => $summary['winner']['formation'], 'sprite' => $manager['appearance'] ?? null],
            ['kind' => 'trophy',  'role' => 'SILVERWARE',      'name' => $summary['name'],        'stat' => null,                     'sprite' => $summary['trophy']],
            ['kind' => 'player',  'role' => 'PLAYER OF FINAL', 'name' => $potf['name'] ?? '—',    'stat' => $potf['stat'] ?? null,    'sprite' => $potf['appearance'] ?? null],
        ];

        return $summary;
    }

    /** @return list<array{round: string, opponent: array<string, mixed>, for: int, against: int, pens: bool}> */
    private function roadToFinal(ActiveCompetition $competition, CompetitionEntrant $winner): array
    {
        $rounds = $this->roundRepository->findByCompetitionOrderedByIndex($competition);
        array_pop($rounds); // the final is shown separately

        $road = [];
        foreach ($rounds as $round) {
            $fixture = $this->em->createQueryBuilder()
                ->select('f')->from(CompetitionFixture::class, 'f')
                ->where('f.round = :round')
                ->andWhere('f.homeEntrant = :e OR f.awayEntrant = :e')
                ->setParameter('round', $round->getId())
                ->setParameter('e', $winner->getId())
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();
            $result = $fixture ? $this->em->getRepository(CompetitionResult::class)->findOneBy(['fixture' => $fixture]) : null;
            if (!$fixture || !$result) {
                continue; // a bye, or a fixture an admin removed
            }

            $home = $this->isHome($fixture, $winner);
            $road[] = [
                'round'    => BracketLabeler::keyForLabel($round->getLabel()),
                'opponent' => $this->clubView($home ? $result->getAwayClubJson() : $result->getHomeClubJson(), 0),
                'for'      => $home ? $result->getHomeScore() : $result->getAwayScore(),
                'against'  => $home ? $result->getAwayScore() : $result->getHomeScore(),
                'pens'     => $result->isWentToPenalties(),
            ];
        }

        return $road;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} [winner, runnerUp] */
    private function sides(CompetitionFixture $fixture, CompetitionResult $result): array
    {
        $home = $this->clubView($result->getHomeClubJson(), $result->getHomeScore());
        $away = $this->clubView($result->getAwayClubJson(), $result->getAwayScore());

        return $this->isHome($fixture, $fixture->getWinnerEntrant()) ? [$home, $away] : [$away, $home];
    }

    private function isHome(CompetitionFixture $fixture, ?CompetitionEntrant $entrant): bool
    {
        return $entrant !== null && $fixture->getHomeEntrant()?->getId()->equals($entrant->getId());
    }

    /** @param array<string, mixed> $club Frozen display block (clubDisplayBlock shape). */
    private function clubView(array $club, int $score): array
    {
        return [
            'name'        => (string) ($club['name'] ?? $club['clubName'] ?? 'Unknown FC'),
            'countryCode' => is_string($club['country'] ?? null) ? $club['country'] : null,
            'score'       => $score,
            // Same {home, away, badge} shape clubBadgeIconsHtml() reads elsewhere on the page.
            'badge'       => ['home' => $club['homeKitConfig'] ?? null, 'away' => $club['awayKitConfig'] ?? null, 'badge' => $club['badgeConfig'] ?? null],
            'kit'         => is_array($club['homeKitConfig'] ?? null) ? $club['homeKitConfig'] : null,
            // Client stadium config, already in the sprite's 1-10 level range.
            'stadium'     => is_array($club['stadium'] ?? null) ? $club['stadium'] : null,
            'stadiumName' => $club['stadiumName'] ?? null,
            'formation'   => $club['formation'] ?? null,
        ];
    }

    /**
     * Highest-rated player on the winning side, dressed in the winner's home kit.
     * Lineups carry `appearance` once passed through formatLineupForClient() — if the
     * stored lineup lacks it, backfill from the entrant's squad the same way
     * MatchQueryService::serializeCupMatchDetail() does.
     *
     * @param array<string, mixed>|null $kit
     */
    private function playerOfTheFinal(CompetitionResult $result, bool $winnerIsHome, ?array $kit): ?array
    {
        // Filtered to arrays before sorting for the same reason formatLineupForClient() does
        // it: the stored lineup is a schema-agnostic json column, so one malformed row must
        // not take the whole board down.
        $lineup = array_values(array_filter(
            $winnerIsHome ? $result->getHomeLineupJson() : $result->getAwayLineupJson(),
            'is_array',
        ));
        usort($lineup, fn (array $a, array $b) => ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0));
        $best = $lineup[0] ?? null;
        if ($best === null) {
            return null;
        }

        $goals = (int) ($best['goals'] ?? 0);
        $appearance = is_array($best['appearance'] ?? null) ? $best['appearance'] : null;
        if ($appearance !== null && $kit !== null) {
            $appearance = array_merge($appearance, array_intersect_key($kit, array_flip(['primary', 'secondary', 'kit', 'shorts', 'socks'])));
        }

        return [
            'name'       => (string) ($best['name'] ?? ''),
            'appearance' => $appearance,
            'stat'       => sprintf('%d %s · %.1f', $goals, $goals === 1 ? 'GOAL' : 'GOALS', (float) ($best['rating'] ?? 0)),
        ];
    }

    /**
     * TODO: wire to the same source OwnerSpotlightService uses for ownerName /
     * ownerAppearance (Owner Identity). Return null to leave the slot empty.
     *
     * @return array{name: string, appearance: ?array}|null
     */
    private function resolveOwner(?Club $club): ?array
    {
        return null;
    }

    /**
     * TODO: the club's Staff row with the manager StaffRole — name + appearance json.
     *
     * @return array{name: string, appearance: ?array}|null
     */
    private function resolveManager(?Club $club): ?array
    {
        return null;
    }
}
