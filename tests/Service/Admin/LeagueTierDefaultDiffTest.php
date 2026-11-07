<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\GameConfig;
use App\Entity\League;
use App\Service\Admin\LeagueTierDefaultDiff;
use App\Enum\Appearance\TrophyStyle;
use App\Service\TrophyConfigFactory;
use PHPUnit\Framework\TestCase;

/**
 * Which leagues count as having drifted from their tier defaults.
 *
 * Pure arithmetic over objects, so no kernel. The cases worth pinning are the three that look
 * like edge cases and are not: tier 1 and promotion, null against zero, and a trophy whose
 * keys arrive in a different order from the one it was saved in.
 */
class LeagueTierDefaultDiffTest extends TestCase
{
    private function config(array $row, int $tier = 2): GameConfig
    {
        $config = new GameConfig();
        $config->setLeagueTierDefaults([(string) $tier => $row]);

        return $config;
    }

    private function league(int $tier, array $values): League
    {
        $league = new League('EN', $tier, 'Test League');

        $league->setPromotionSpots($values['promotionSpots'] ?? null);
        $league->setTvDeal($values['tvDeal'] ?? null);
        $league->setPrizeMoney($values['prizeMoney'] ?? null);
        $league->setLeaguePositionPot($values['leaguePositionPot'] ?? null);
        $league->setSponsorCount($values['sponsorCount'] ?? 0);
        $league->setTrophy($values['trophy'] ?? null);

        return $league;
    }

    public function testALeagueMatchingItsTierDiffersInNothing(): void
    {
        $row = ['promotionSpots' => 3, 'tvDeal' => 100000, 'prizeMoney' => 50000,
                'leaguePositionPot' => 25000, 'sponsorCount' => 4, 'trophy' => null];

        self::assertSame([], LeagueTierDefaultDiff::forLeague($this->league(2, $row), $this->config($row)));
    }

    public function testEachDriftedFieldIsNamed(): void
    {
        $row = ['promotionSpots' => 3, 'tvDeal' => 100000, 'prizeMoney' => 50000,
                'leaguePositionPot' => 25000, 'sponsorCount' => 4, 'trophy' => null];

        $league = $this->league(2, ['promotionSpots' => 3, 'tvDeal' => 999, 'prizeMoney' => 50000,
                                    'leaguePositionPot' => 25000, 'sponsorCount' => 9, 'trophy' => null]);

        self::assertSame(['tvDeal', 'sponsorCount'], LeagueTierDefaultDiff::forLeague($league, $this->config($row)));
    }

    /** Nobody is promoted into the top tier, so its promotion figure is not compared. */
    public function testTierOneIgnoresPromotionSpots(): void
    {
        $row = ['promotionSpots' => 3, 'tvDeal' => null, 'prizeMoney' => null,
                'leaguePositionPot' => null, 'sponsorCount' => 0, 'trophy' => null];

        $top = $this->league(1, ['promotionSpots' => 0] + $row);
        self::assertSame([], LeagueTierDefaultDiff::forLeague($top, $this->config($row, 1)));

        // The same mismatch one tier down IS a difference.
        $second = $this->league(2, ['promotionSpots' => 0] + $row);
        self::assertSame(['promotionSpots'], LeagueTierDefaultDiff::forLeague($second, $this->config($row)));
    }

    /** "No TV deal" is not "a TV deal of nothing". */
    public function testNullIsNotZero(): void
    {
        $row = ['promotionSpots' => null, 'tvDeal' => null, 'prizeMoney' => null,
                'leaguePositionPot' => null, 'sponsorCount' => 0, 'trophy' => null];

        $league = $this->league(2, ['tvDeal' => 0] + $row);

        self::assertSame(['tvDeal'], LeagueTierDefaultDiff::forLeague($league, $this->config($row)));
    }

    /**
     * A trophy is an associative array from two different sources — a form and stored JSON —
     * so its keys need not arrive in the same order. Comparing with === would report a trophy
     * as differing from an identical copy of itself.
     */
    public function testTheSameTrophyInADifferentKeyOrderIsNotADifference(): void
    {
        // Both sides are put through TrophyConfigFactory before comparing, so the shapes match;
        // what is being pinned here is that key ORDER does not count as a difference. `===`
        // on arrays is order-sensitive and would report a trophy as differing from itself.
        $canonical = TrophyConfigFactory::validateNullable(TrophyConfigFactory::defaults());
        self::assertNotNull($canonical);

        $shuffled = array_reverse($canonical, true);
        self::assertNotSame(array_keys($canonical), array_keys($shuffled), 'the orders must differ');

        $row = ['promotionSpots' => null, 'tvDeal' => null, 'prizeMoney' => null,
                'leaguePositionPot' => null, 'sponsorCount' => 0, 'trophy' => $canonical];

        $league = $this->league(2, ['trophy' => $shuffled] + $row);

        self::assertSame([], LeagueTierDefaultDiff::forLeague($league, $this->config($row)));
    }

    /**
     * A trophy stored before normalisation existed still compares equal to its tier.
     *
     * The tier row's trophy goes through TrophyConfigFactory on the way out of GameConfig; a
     * league's does not. Without putting both through it, such a league would show as drifted
     * from a trophy identical to its own, and applying the defaults would not clear it.
     */
    public function testAnUnnormalisedStoredTrophyStillMatches(): void
    {
        $canonical = TrophyConfigFactory::validateNullable(TrophyConfigFactory::defaults());
        self::assertNotNull($canonical);

        $row = ['promotionSpots' => null, 'tvDeal' => null, 'prizeMoney' => null,
                'leaguePositionPot' => null, 'sponsorCount' => 0, 'trophy' => $canonical];

        // Only the style, as an older write might have left it.
        $league = $this->league(2, ['trophy' => ['style' => $canonical['style']]] + $row);

        self::assertSame([], LeagueTierDefaultDiff::forLeague($league, $this->config($row)));
    }

    public function testADifferentTrophyIsADifference(): void
    {
        // Real TrophyStyle cases: the validator falls an unknown style back to the default, so
        // two invented names would normalise to the same trophy and prove nothing.
        $row = ['promotionSpots' => null, 'tvDeal' => null, 'prizeMoney' => null,
                'leaguePositionPot' => null, 'sponsorCount' => 0,
                'trophy' => ['style' => TrophyStyle::GOBLET->value]];

        $league = $this->league(2, ['trophy' => ['style' => TrophyStyle::WINGED->value]] + $row);

        self::assertSame(['trophy'], LeagueTierDefaultDiff::forLeague($league, $this->config($row)));
    }

    public function testForLeaguesKeysOnLeagueId(): void
    {
        $row = ['promotionSpots' => null, 'tvDeal' => null, 'prizeMoney' => null,
                'leaguePositionPot' => null, 'sponsorCount' => 0, 'trophy' => null];

        $map = LeagueTierDefaultDiff::forLeagues([$this->league(2, $row)], $this->config($row));

        self::assertCount(1, $map);
        self::assertSame([], reset($map));
    }
}
