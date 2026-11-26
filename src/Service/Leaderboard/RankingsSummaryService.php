<?php

declare(strict_types=1);

namespace App\Service\Leaderboard;

use App\Dto\Leaderboard\LeaderboardItemDto;
use App\Entity\Club;
use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardAwardPlacement;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Enum\Leaderboard\PeriodArchiveStatus;
use App\Repository\Leaderboard\LeaderboardAwardDefinitionRepository;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveEntryRepository;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveRepository;
use App\Service\LeaderboardCalculationService;
use App\Service\TrophyConfigFactory;

/**
 * Everything the app's Rankings dashboard shows, for one club, in one payload.
 *
 * "Active" means an ENABLED award definition — the admin-configured set, so the number of
 * award types is whatever the admin has switched on, never a list baked into the client.
 * Awards are sorted by the end of their current period, soonest first, because that is the
 * dashboard's whole ordering rule.
 *
 * Periods are real-world UTC calendar periods (ISO week / month / quarter), so `endsAt` and
 * `serverTime` are both sent: the client counts down from the server's clock, not the
 * phone's.
 *
 * Reads only. The live board comes from LeaderboardCalculationService::getRankedItems(), the
 * same cache document GET /api/leaderboard/{category} slices, so "you are 7th" here and the
 * full board the player taps into cannot disagree. A cold cache recalculates exactly as the
 * public read already does.
 */
class RankingsSummaryService
{
    /** Rows shown per award card. */
    public const TOP_N = 3;

    /** Awards listed in the player's history. */
    public const HISTORY_LIMIT = 20;

    public function __construct(
        private readonly LeaderboardAwardDefinitionRepository $definitions,
        private readonly LeaderboardCalculationService $calculation,
        private readonly LeaderboardPeriodArchiveRepository $archives,
        private readonly LeaderboardPeriodArchiveEntryRepository $archiveEntries,
    ) {}

    /** @return array<string, mixed> */
    public function build(Club $club, ?\DateTimeImmutable $now = null): array
    {
        $now    = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $clubId = (string) $club->getId();

        $awards = [];
        foreach ($this->definitions->findEnabled() as $definition) {
            $award = $this->award($definition, $club, $clubId, $now);
            if ($award !== null) {
                $awards[] = $award;
            }
        }

        // Soonest to finish first; a tie (two weekly awards) falls back to the name so the
        // order is stable between calls.
        usort($awards, static fn (array $a, array $b): int => [$a['endsAt'], $a['name']] <=> [$b['endsAt'], $b['name']]);

        return [
            'serverTime' => $now->format(DATE_ATOM),
            'awards'     => $awards,
            'myAwards'   => $this->history((string) $club->getUser()->getId()),
        ];
    }

    /** @return array<string, mixed>|null */
    private function award(LeaderboardAwardDefinition $definition, Club $club, string $clubId, \DateTimeImmutable $now): ?array
    {
        $periodKey = $definition->currentPeriodKey($now);
        $bounds    = LeaderboardPeriod::boundsOf($periodKey);

        if ($bounds === null) {
            return null;
        }

        $items = $this->calculation->getRankedItems($definition->getCategory(), $periodKey);
        $depth = $definition->getDepth();

        $mine = null;
        foreach ($items as $item) {
            if ($item->clubId === $clubId) {
                $mine = $item;
                break;
            }
        }

        $placements = $definition->getPlacements()->toArray();
        usort($placements, static fn (LeaderboardAwardPlacement $a, LeaderboardAwardPlacement $b): int => $a->getPlace() <=> $b->getPlace());

        return [
            'definitionId' => (string) $definition->getId(),
            'name'         => (string) $definition,
            'category'     => $definition->getCategory()->value,
            'cadence'      => $definition->getCadence()->value,
            'cadenceLabel' => $definition->getCadence()->label(),
            'periodKey'    => $periodKey,
            'periodLabel'  => LeaderboardPeriod::label($periodKey),
            'startsAt'     => $bounds[0]->format(DATE_ATOM),
            'endsAt'       => $bounds[1]->format(DATE_ATOM),
            'minimumScore' => $definition->getMinimumScore(),
            'trophy'       => TrophyConfigFactory::validateNullable($definition->getTrophy()) ?? TrophyConfigFactory::defaults(),
            'prizes'       => array_map($this->prize(...), $placements),
            'entrants'     => count($items),
            'top'          => array_map($this->row(...), array_slice($items, 0, self::TOP_N)),
            'me'           => $this->me($club, $mine, $items, $depth),
            'lastWinner'   => $this->lastWinner($definition),
        ];
    }

    /**
     * The requesting club's line on the board.
     *
     * `rank` null with a reason when the club is not on the board: `first_season` mirrors
     * LeaderboardEntryRepository::applyEligibility() (a club on its first season is never
     * listed), `not_ranked` is everything else (nothing gained yet this period).
     *
     * `gapToPrize` is how many points behind the LAST prize place the club is, so the card
     * can say "9 behind a prize". Null when the club is already in a prize place, or when
     * there are fewer clubs than prize places (any score would place).
     *
     * @param LeaderboardItemDto[] $items
     * @return array<string, mixed>
     */
    private function me(Club $club, ?LeaderboardItemDto $mine, array $items, int $depth): array
    {
        if ($mine === null) {
            return [
                'rank'       => null,
                'score'      => null,
                'reason'     => $club->getCurrentSeason() <= 1 ? 'first_season' : 'not_ranked',
                'inPrize'    => false,
                'gapToPrize' => null,
            ];
        }

        $inPrize    = $depth > 0 && $mine->rank <= $depth;
        $gapToPrize = null;
        if (!$inPrize && $depth > 0 && isset($items[$depth - 1])) {
            $gapToPrize = max(0, $items[$depth - 1]->score - $mine->score);
        }

        return [
            'rank'       => $mine->rank,
            'score'      => $mine->score,
            'reason'     => null,
            'inPrize'    => $inPrize,
            'gapToPrize' => $gapToPrize,
        ];
    }

    /** @return array<string, mixed> */
    private function prize(LeaderboardAwardPlacement $placement): array
    {
        $template = $placement->getRewardTemplate();

        return [
            'place'           => $placement->getPlace(),
            'prizePence'      => $placement->getPrizePence(),
            'reputationDelta' => $placement->getReputationDelta(),
            'rewardName'      => $template !== null && $template->isActive() ? $template->getName() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function row(LeaderboardItemDto $item): array
    {
        return [
            'rank'          => $item->rank,
            'clubId'        => $item->clubId,
            'clubName'      => $item->clubName,
            'score'         => $item->score,
            'displayLabel'  => $item->displayLabel,
            'homeKitConfig' => $item->homeKitConfig,
            'badgeConfig'   => $item->badgeConfig,
            'country'       => $item->country,
        ];
    }

    /**
     * The newest ARCHIVED period for this definition and who took first place in it. Pending
     * and skipped closes are passed over, as on the landing page.
     *
     * @return array<string, mixed>|null
     */
    private function lastWinner(LeaderboardAwardDefinition $definition): ?array
    {
        foreach ($this->archives->findForDefinition($definition, 3) as $archive) {
            if ($archive->getStatus() !== PeriodArchiveStatus::ARCHIVED) {
                continue;
            }

            $top = $this->archiveEntries->findForArchive($archive, 1)[0] ?? null;
            if ($top === null) {
                continue;
            }

            return [
                'periodKey'   => $archive->getPeriodKey(),
                'periodLabel' => LeaderboardPeriod::label($archive->getPeriodKey()),
                'clubId'      => $top->getClubId(),
                'clubName'    => $top->getClubName(),
                'displayLabel' => $top->getDisplayLabel(),
                'score'       => $top->getScore(),
                'badgeConfig' => $top->getBadgeConfig(),
                'entrants'    => $archive->getEntryCount(),
            ];
        }

        return null;
    }

    /**
     * Every award this user has won, newest first. User-keyed rather than club-keyed on
     * purpose: the archive freezes user_id, so an award survives the owner moving clubs.
     *
     * Prize and reputation come from the effects actually recorded at close, not today's
     * placement config, which an admin may have changed since.
     *
     * @return list<array<string, mixed>>
     */
    private function history(string $userId): array
    {
        $entries = array_slice($this->archiveEntries->findAwardedForUser($userId), 0, self::HISTORY_LIMIT);

        return array_map(static function (LeaderboardPeriodArchiveEntry $entry): array {
            $archive    = $entry->getArchive();
            $prizePence = 0;
            $reputation = 0;
            foreach ($entry->getAppliedEffectsJson() ?? [] as $effect) {
                if (($effect['type'] ?? null) === 'ledger_delta') {
                    $prizePence += (int) ($effect['amountPence'] ?? 0);
                } elseif (($effect['type'] ?? null) === 'reputation_delta') {
                    $reputation += (int) ($effect['amount'] ?? 0);
                }
            }

            return [
                'id'              => (string) $entry->getId(),
                'name'            => $archive->getDefinitionName(),
                'category'        => $archive->getCategory()->value,
                'cadence'         => $archive->getCadence()->value,
                'periodKey'       => $archive->getPeriodKey(),
                'periodLabel'     => LeaderboardPeriod::label($archive->getPeriodKey()),
                'place'           => $entry->getPlace(),
                'score'           => $entry->getScore(),
                'clubName'        => $entry->getClubName(),
                'prizePence'      => $prizePence,
                'reputationDelta' => $reputation,
                'trophy'          => self::trophyFor($archive),
                'closedAt'        => $archive->getClosedAt()?->format(DATE_ATOM),
            ];
        }, $entries);
    }

    /** @return array<string, mixed> */
    private static function trophyFor(LeaderboardPeriodArchive $archive): array
    {
        $definition = $archive->getDefinition();

        return TrophyConfigFactory::validateNullable($definition?->getTrophy()) ?? TrophyConfigFactory::defaults();
    }
}
