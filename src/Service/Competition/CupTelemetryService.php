<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionResult;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The CUPS band inside the Live Telemetry terminal.
 *
 * Returns null when no cup has EVER completed and none is live or open — the
 * template then renders the terminal exactly as it did before cups existed.
 *
 * Unlike the telemetry snapshot this is read live: countdowns are only honest
 * if the round times are current. All reads are indexed and bounded
 * (LIVE_LIMIT + OPEN_LIMIT + WINNER_LIMIT cups). Put a 60s cache in front if the
 * landing page's traffic warrants it — the JS counts down client-side, so a
 * minute-old payload still shows correct timers.
 *
 * Timer semantics, from CompetitionRound:
 *   DRAWN        → "RESULTS IN"  → matchesResolveAt
 *   DRAW_PENDING → "DRAW IN"     → scheduledAt
 * matchesResolveAt is NULLABLE, so a drawn round may have no timer at all. Both the
 * band's sort and its nextResultAt exclude a null rather than compare it — PHP orders
 * null below every DateTimeImmutable, so an untimed cup would otherwise sort as the most
 * imminent of all and win min() over the result times, hiding the header's countdown.
 * The template gates every timer read on the same value.
 *
 * Every club identity here is the FROZEN copy from CompetitionResult, never the live
 * Club — see settledFinal(). A cup won by a club that has since been deleted still names
 * and badges it, the same property CupResultsBoardService relies on for #cup-results.
 *
 * recentWinnerRows() is the second caller and the odd one out: it serves
 * LiveTelemetryService's `CUP // WINNER` feed lines, so it is bounded by a time WINDOW
 * ("won in the last 7 days") where the band's winners are bounded by a COUNT ("the last
 * three ever"). It returns rows, not sentences — the feed composes its own wording.
 */
final class CupTelemetryService
{
    public const LIVE_LIMIT   = 6;
    public const OPEN_LIMIT   = 3;
    public const WINNER_LIMIT = 3;
    private const FIXTURES_SHOWN = 2;

    private const ROUND_NAMES = [
        'R64' => 'ROUND OF 64', 'R32' => 'ROUND OF 32', 'R16' => 'ROUND OF 16',
        'QF' => 'QUARTER-FINALS', 'SF' => 'SEMI-FINALS', 'FINAL' => 'FINAL',
    ];

    public function __construct(
        private readonly ActiveCompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return array{live: list<array>, open: list<array>, winners: list<array>, nextResultAt: ?\DateTimeImmutable}|null */
    public function getBand(): ?array
    {
        $live    = array_values(array_filter(array_map(fn ($c) => $this->liveCup($c), array_slice($this->competitionRepository->findActive(), 0, self::LIVE_LIMIT))));
        $open    = array_map(fn ($c) => $this->openCup($c), array_slice($this->competitionRepository->findByStatus(ActiveCompetitionStatus::REGISTERING), 0, self::OPEN_LIMIT));
        $winners = array_values(array_filter(array_map(fn ($c) => $this->winner($c), $this->competitionRepository->findRecentlyCompletedWithKnownCompletion(self::WINNER_LIMIT))));

        if ($live === [] && $open === [] && $winners === []) {
            return null;
        }

        // Finals first — they are the tile the band is built around — then soonest first.
        // A cup with no timer sorts last within its group: PHP compares null below any
        // DateTimeImmutable, so it would otherwise read as the most imminent of all.
        usort($live, fn ($a, $b) => [$b['isFinal'], $a['timerAt'] === null, $a['timerAt']] <=> [$a['isFinal'], $b['timerAt'] === null, $b['timerAt']]);

        $resultTimes = array_filter(
            array_map(fn ($c) => $c['timerAt'], array_filter($live, fn ($c) => $c['timerLabel'] === 'RESULTS IN')),
        );

        return [
            'live'         => $live,
            'open'         => $open,
            'winners'      => $winners,
            // min() over the non-null times only — a single null in here would win the
            // comparison and hide the header's countdown entirely.
            'nextResultAt' => $resultTimes === [] ? null : min($resultTimes),
        ];
    }

    private function liveCup(ActiveCompetition $c): ?array
    {
        $round = $this->roundRepository->findCurrentRound($c);
        if ($round === null) {
            return null; // locked but not yet drawn into rounds
        }

        $drawn    = $round->getStatus() === CompetitionRoundStatus::DRAWN;
        $label    = $round->getLabel();
        $fixtures = [];

        if ($drawn) {
            $rows = $this->em->getRepository(CompetitionFixture::class)
                ->findBy(['round' => $round], ['slotIndex' => 'ASC'], $label === 'FINAL' ? 1 : self::FIXTURES_SHOWN);
            foreach ($rows as $fx) {
                if ($fx->getHomeEntrant() && $fx->getAwayEntrant()) {
                    $fixtures[] = [
                        'home' => $this->side($fx->getHomeEntrant()->getClubDisplayJson()),
                        'away' => $this->side($fx->getAwayEntrant()->getClubDisplayJson()),
                    ];
                }
            }
        }

        $template = $c->getTemplate();

        return [
            'name'       => $template->getName(),
            'trophy'     => $template->getTrophy(),
            'prizePence' => $template->getVictorPrize(),
            'capacity'   => $c->getEntrantCapacity(),
            'roundKey'   => BracketLabeler::keyForLabel($label),
            'roundName'  => self::ROUND_NAMES[$label] ?? $label,
            'clubsLeft'  => 2 * BracketLabeler::slotCountForRound($c->getEntrantCapacity(), $round->getRoundIndex()),
            'isFinal'    => $label === 'FINAL' && $drawn && $fixtures !== [],
            'fixtures'   => $fixtures,
            'timerLabel' => $drawn ? 'RESULTS IN' : 'DRAW IN',
            // Nullable: matchesResolveAt is only set once a round is scheduled to resolve.
            // The template gates on it rather than formatting null, which Twig's `date`
            // filter would render as the current time — a countdown to right now.
            'timerAt'    => $drawn ? $round->getMatchesResolveAt() : $round->getScheduledAt(),
        ];
    }

    private function openCup(ActiveCompetition $c): array
    {
        $template = $c->getTemplate();

        return [
            'name'          => $template->getName(),
            'trophy'        => $template->getTrophy(),
            'capacity'      => $c->getEntrantCapacity(),
            'entered'       => $this->entrantRepository->countForCompetition($c),
            'entryFeePence' => $template->getEntryFeePence(),
            'prizePence'    => $template->getVictorPrize(),
        ];
    }

    private function winner(ActiveCompetition $c): ?array
    {
        $settled = $this->settledFinal($c);
        if ($settled === null) {
            return null;
        }

        return [
            'clubName' => $settled['clubName'],
            'badge'    => $settled['clubBadge'],
            'trophy'   => $c->getTemplate()->getTrophy(),
            'cupName'  => $c->getTemplate()->getName(),
            'ago'      => $this->ago($c->getCompletedAt()),
        ];
    }

    /**
     * Cups won inside $since, as data for the telemetry feed's `CUP // WINNER` lines.
     *
     * Returns rows, not sentences: LiveTelemetryService composes every feed line's text
     * itself (see its build* methods), so the wording of a cup win lives beside the wording
     * of a promotion rather than over here.
     *
     * @return list<array{clubName: string, clubId: ?string, clubBadge: array{home: ?array, away: ?array, badge: ?array}, trophy: ?array, cupName: string, winnerScore: int, loserScore: int, wentToExtraTime: bool, wentToPenalties: bool, penaltyWinner: ?int, penaltyLoser: ?int, prizePence: int, completedAt: \DateTimeImmutable}>
     */
    public function recentWinnerRows(\DateTimeImmutable $since, int $limit): array
    {
        $rows = [];

        foreach ($this->competitionRepository->findCompletedSince($since, $limit) as $c) {
            $settled    = $this->settledFinal($c);
            $completedAt = $c->getCompletedAt();
            // findCompletedSince() filters completedAt IS NOT NULL, so the second check is
            // only here to satisfy the nullable getter rather than a state it can reach.
            if ($settled === null || $completedAt === null) {
                continue;
            }

            $template = $c->getTemplate();
            $rows[]   = $settled + [
                'trophy'      => $template->getTrophy(),
                'cupName'     => $template->getName(),
                'prizePence'  => $template->getVictorPrize(),
                'completedAt' => $completedAt,
            ];
        }

        return $rows;
    }

    /**
     * The winning side of a competition's final, read ONLY from the frozen club copy on
     * CompetitionResult — never off the live Club, so a cup won by a since-deleted club
     * still names it. Null when the last round holds no settled, won slot-0 fixture.
     *
     * @return array{clubName: string, clubId: ?string, clubBadge: array{home: ?array, away: ?array, badge: ?array}, winnerScore: int, loserScore: int, wentToExtraTime: bool, wentToPenalties: bool, penaltyWinner: ?int, penaltyLoser: ?int}|null
     */
    private function settledFinal(ActiveCompetition $c): ?array
    {
        $rounds = $this->roundRepository->findByCompetitionOrderedByIndex($c);
        $final  = end($rounds);
        if ($final === false) {
            return null;
        }

        $fixture = $this->em->getRepository(CompetitionFixture::class)->findOneBy(['round' => $final, 'slotIndex' => 0]);
        $result  = $fixture ? $this->em->getRepository(CompetitionResult::class)->findOneBy(['fixture' => $fixture]) : null;
        $winner  = $fixture?->getWinnerEntrant();
        if (!$result || !$winner) {
            return null;
        }

        // Frozen copy from the result, never the live club.
        $homeWon = $fixture->getHomeEntrant()?->getId()->equals($winner->getId()) === true;
        $club    = $homeWon ? $result->getHomeClubJson() : $result->getAwayClubJson();
        $clubId  = $club['clubId'] ?? $club['id'] ?? null;

        return [
            'clubName' => (string) ($club['name'] ?? $club['clubName'] ?? 'Unknown FC'),
            // The real Club row behind the winner, where the frozen block recorded one — the
            // feed keys its boardroom dossier on this, never on the name.
            'clubId'   => is_string($clubId) && $clubId !== '' ? $clubId : null,
            'clubBadge' => $this->badge($club),
            'winnerScore' => $homeWon ? $result->getHomeScore() : $result->getAwayScore(),
            'loserScore'  => $homeWon ? $result->getAwayScore() : $result->getHomeScore(),
            'wentToExtraTime' => $result->isWentToExtraTime(),
            'wentToPenalties' => $result->isWentToPenalties(),
            'penaltyWinner'   => $homeWon ? $result->getPenaltyHomeScore() : $result->getPenaltyAwayScore(),
            'penaltyLoser'    => $homeWon ? $result->getPenaltyAwayScore() : $result->getPenaltyHomeScore(),
        ];
    }

    /** @param array<string, mixed> $club */
    private function side(array $club): array
    {
        return [
            'name'  => (string) ($club['name'] ?? $club['clubName'] ?? 'Unknown FC'),
            'abbr'  => (string) ($club['abbreviation'] ?? ''),
            'badge' => $this->badge($club),
        ];
    }

    /** Same {home, away, badge} shape clubBadgeIconsHtml() reads. */
    private function badge(array $club): array
    {
        return ['home' => $club['homeKitConfig'] ?? null, 'away' => $club['awayKitConfig'] ?? null, 'badge' => $club['badgeConfig'] ?? null];
    }

    /** Matches the feed's own "[6d ago]" style. */
    private function ago(?\DateTimeImmutable $at): string
    {
        if ($at === null) {
            return '—';
        }
        $s = max(0, time() - $at->getTimestamp());

        return match (true) {
            $s < 3600  => max(1, intdiv($s, 60)) . 'm ago',
            $s < 86400 => intdiv($s, 3600) . 'h ago',
            default    => intdiv($s, 86400) . 'd ago',
        };
    }
}
