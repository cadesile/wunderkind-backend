<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use App\Entity\League;
use PHPUnit\Framework\TestCase;

/**
 * The parachute block is a VERBATIM PASSTHROUGH, and these tests exist to keep it one.
 *
 * WHY THAT NEEDS DEFENDING RATHER THAN JUST DOCUMENTING. "Store this JSON and don't look
 * inside" is an unstable decision: the next person to touch it will reasonably want to
 * validate a shape, normalise a key, or convert pounds to pence, and each of those quietly
 * makes the backend a second author of a schedule only the app can execute. So the property
 * under test is a NEGATIVE one — that an arbitrary structure survives a round trip unchanged,
 * including shapes nobody designed.
 *
 * The one transformation that IS deliberate is `[]` to null, because an empty override and "no
 * override" are indistinguishable and only one of them is a thing somebody authors. That is
 * tested too, so it cannot be mistaken for the validation this class refuses to do.
 */
class LeagueParachuteTest extends TestCase
{
    // ── Verbatim means verbatim ───────────────────────────────────────────

    public function testAnArbitraryStructureSurvivesUnchanged(): void
    {
        // Nested, mixed types, keys the backend has never heard of. If any of this comes back
        // altered, something has started interpreting the block.
        $block = [
            'seasons'      => 3,
            'perSeasonPence' => [5_500_000_00, 4_500_000_00, 2_000_000_00],
            'onlyIfRelegatedFrom' => ['tier' => 1],
            'notes'        => 'authored 2026-11-12',
            'enabled'      => true,
            'taper'        => 0.55,
            'deeplyNested' => ['a' => ['b' => ['c' => [1, 2, 3]]]],
        ];

        $league = (new League('EN', 1, 'League 1'))->setParachute($block);

        self::assertSame($block, $league->getParachute());
    }

    public function testKeysTheBackendDoesNotKnowAreNotStripped(): void
    {
        $block  = ['somethingTheAppInventedLater' => ['v' => 2]];
        $league = (new League('EN', 1, 'League 1'))->setParachute($block);

        self::assertSame($block, $league->getParachute());
    }

    public function testAListIsStoredAsAList(): void
    {
        // The app may well decide a parachute is just an array of per-season amounts. Nothing
        // here requires it to be an object.
        $block  = [5_500_000_00, 4_500_000_00];
        $league = (new League('EN', 1, 'League 1'))->setParachute($block);

        self::assertSame($block, $league->getParachute());
    }

    public function testAShapeThatMakesNoSenseIsStillStored(): void
    {
        // THE ACCEPTED COST OF THE DECISION, asserted so nobody "fixes" it later: a malformed
        // block is stored and served, and only the app notices. Same trade
        // starter_config.setup_tutorial_config makes.
        $block  = ['seasons' => 'three', 'perSeasonPence' => 'lots'];
        $league = (new League('EN', 1, 'League 1'))->setParachute($block);

        self::assertSame($block, $league->getParachute());
    }

    // ── The one deliberate transformation ─────────────────────────────────

    public function testAnEmptyBlockBecomesNull(): void
    {
        // Not validation — a disambiguation. `[]` would resolve to "this league pays no
        // parachute" while the global config said otherwise, which is not a state anybody
        // authors on purpose.
        self::assertNull((new League('EN', 1, 'L'))->setParachute([])->getParachute());
        self::assertNull((new GameConfig())->setLeagueParachute([])->getLeagueParachute());
    }

    public function testNullIsNull(): void
    {
        self::assertNull((new League('EN', 1, 'L'))->setParachute(null)->getParachute());
        self::assertNull((new GameConfig())->getLeagueParachute(), 'the shipped state');
    }

    public function testAnOverrideCanBeClearedAgain(): void
    {
        // The operator's only route to removing an override is to blank the field, so going
        // back to null has to work rather than being a one-way door.
        $league = (new League('EN', 1, 'L'))->setParachute(['seasons' => 2]);
        self::assertNotNull($league->getParachute());

        $league->setParachute(null);
        self::assertNull($league->getParachute());
    }

    // ── Resolution ────────────────────────────────────────────────────────

    public function testTheLeagueOverrideWinsWholeValue(): void
    {
        // WHOLE-VALUE, NOT MERGED. Merging would need the backend to understand the keys,
        // which is the thing it declines to do — and a half-overridden schedule is a worse
        // answer than either whole one. So the global's extra key must NOT leak through.
        $config = (new GameConfig())->setLeagueParachute(['seasons' => 3, 'globalOnlyKey' => true]);
        $league = (new League('EN', 1, 'L'))->setParachute(['seasons' => 1]);

        $resolved = $league->getParachute() ?? $config->getLeagueParachute();

        self::assertSame(['seasons' => 1], $resolved);
        self::assertArrayNotHasKey('globalOnlyKey', $resolved);
    }

    public function testTheGlobalAppliesWhenTheLeagueHasNoOverride(): void
    {
        $config = (new GameConfig())->setLeagueParachute(['seasons' => 3]);
        $league = new League('EN', 1, 'L');

        self::assertSame(['seasons' => 3], $league->getParachute() ?? $config->getLeagueParachute());
    }

    public function testNeitherAuthoredResolvesToNull(): void
    {
        // The shipped state, and what makes the migration a no-op: a key an old build does not
        // read, and a new build reads as "no scheme configured" rather than an empty one.
        $config = new GameConfig();
        $league = new League('EN', 1, 'L');

        self::assertNull($league->getParachute() ?? $config->getLeagueParachute());
    }

    public function testAnEmptyLeagueOverrideFallsThroughToTheGlobal(): void
    {
        // The consequence of `[]` becoming null, stated as the behaviour it produces: clearing
        // a league's override restores the global rather than silently switching parachutes
        // off for that league.
        $config = (new GameConfig())->setLeagueParachute(['seasons' => 3]);
        $league = (new League('EN', 1, 'L'))->setParachute([]);

        self::assertSame(['seasons' => 3], $league->getParachute() ?? $config->getLeagueParachute());
    }

    // ── The tier-default row stays total ──────────────────────────────────

    public function testEveryTierDefaultRowCarriesTheKey(): void
    {
        // Callers read keys off this result directly (LeagueService::generateLeaguesForCountry(),
        // the admin's bulk apply, LeagueTierDefaultDiff), so a missing key is an undefined-index
        // warning that surfaces as a league created with no parachute rather than as an error.
        $config = new GameConfig();

        foreach (range(1, 8) as $tier) {
            self::assertArrayHasKey('parachute', $config->getLeagueTierDefaultsForTier($tier), "tier {$tier}");
        }
    }

    public function testATierRowSavedBeforeTheColumnExistedStillResolves(): void
    {
        // This column is plain stored JSON, so a row written before the parachute existed — or
        // a config export taken back then, which admins are told is safe to re-import — carries
        // no such key. normaliseTierRow() fills it.
        $config = new GameConfig();
        $config->setLeagueTierDefaults([
            '1' => ['promotionSpots' => null, 'tvDeal' => 1, 'prizeMoney' => 1, 'leaguePositionPot' => 1, 'sponsorCount' => 0, 'trophy' => null],
        ]);

        $row = $config->getLeagueTierDefaultsForTier(1);

        self::assertArrayHasKey('parachute', $row);
        self::assertNull($row['parachute']);
        self::assertSame(1, $row['tvDeal'], 'the keys that WERE there must pass through untouched');
    }
}
