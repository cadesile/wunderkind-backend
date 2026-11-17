<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GameConfig;
use App\Service\Economy\EconomyConfigPublisher;
use App\Service\Economy\EconomyScaleResolver;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\SyncService;
use PHPUnit\Framework\TestCase;

/**
 * The two branches of SyncService's `gameConfig` ternary must carry an IDENTICAL key set.
 *
 * WHY THIS IS WORTH A TEST THAT READS ITS OWN SOURCE FILE. That block is ~63 keys written out
 * twice — once read off the entity, once as literals for the case where no `GameConfig` row
 * exists at all. A key added to one branch and forgotten on the other does not fail anything:
 * it produces a response that is correct in the normal case and missing a key in the rare
 * no-config case, which is the exact case nobody exercises by hand. The client's guard is
 * `?? fallback` rather than a falsiness check, so the symptom downstream is a silently wrong
 * engine constant rather than an error.
 *
 * ORDER IS ASSERTED, NOT JUST MEMBERSHIP. Two lists with the same members in a different order
 * are a sign somebody edited one branch and patched the other to match, which is how the next
 * key goes in the wrong place. Keeping them literally parallel is what makes the two readable
 * side by side.
 *
 * PARSED FROM THE FILE rather than exercised through a request, because the fallback branch is
 * unreachable: `GameConfigRepository::getConfig()` creates the singleton row when it is absent,
 * so no amount of fixture setup makes `$gameConfig` null. The branch is a safety net, and a
 * safety net that cannot be reached is exactly the thing that rots — so it is checked
 * statically. The companion test below then proves the economy block's own two shapes agree
 * at runtime.
 */
class SyncServiceGameConfigParityTest extends TestCase
{
    public function testBothTernaryBranchesCarryTheSameKeysInTheSameOrder(): void
    {
        [$entityBranch, $fallbackBranch] = $this->branchKeys();

        self::assertNotEmpty($entityBranch, 'the ternary has moved — this test needs updating');

        self::assertSame(
            $entityBranch,
            $fallbackBranch,
            "SyncService's gameConfig ternary branches have drifted. A key present on one "
            . 'branch and absent from the other is invisible until a client hits the no-config '
            . 'case. Add it to both, in the same position.',
        );
    }

    /**
     * Neither branch may restate a key the economy builder already spreads in.
     *
     * A hand-written `'inflationRatePerSeasonBp' => ...` next to the spread would win or lose
     * depending on which came first, and the whole point of the builder is that there is one
     * answer.
     */
    public function testNeitherBranchRestatesAnEconomyKey(): void
    {
        [$entityBranch, $fallbackBranch] = $this->branchKeys();
        $economyKeys = array_keys($this->publisher()->clientBlock(null, null));

        foreach ($economyKeys as $key) {
            self::assertNotContains($key, $entityBranch, "{$key} is spread in, not written out");
            self::assertNotContains($key, $fallbackBranch, "{$key} is spread in, not written out");
        }
    }

    /** Both branches must actually spread the builder in — the parity above is only as good as that. */
    public function testBothBranchesSpreadTheEconomyBuilder(): void
    {
        $source = $this->syncServiceSource();
        $start  = strpos($source, "'cliqueRelationshipThreshold'");
        $middle = strpos($source, '] : [', (int) $start);
        $end    = strpos($source, "\n        ];", (int) $middle);

        $entity   = substr($source, (int) $start, (int) $middle - (int) $start);
        $fallback = substr($source, (int) $middle, (int) $end - (int) $middle);

        self::assertStringContainsString('economyConfigPublisher->clientBlock(', $entity);
        self::assertStringContainsString('economyConfigPublisher->clientBlock(', $fallback);
    }

    // ── The economy block's own two shapes ────────────────────────────────

    public function testTheEconomyBlockHasTheSameShapeWithAndWithoutAConfig(): void
    {
        $publisher = $this->publisher();

        $withConfig = $publisher->clientBlock(new GameConfig(), 'EN');
        $without    = $publisher->clientBlock(null, 'EN');

        self::assertSame(array_keys($withConfig), array_keys($without));
        // A fresh GameConfig IS the fallback, so the values match too — which is the property
        // that makes "restated literals" unnecessary.
        self::assertSame($withConfig, $without);
    }

    public function testTheEconomyBlockHasTheSameShapeWithAndWithoutACountry(): void
    {
        $publisher = $this->publisher();

        self::assertSame(
            array_keys($publisher->clientBlock(new GameConfig(), 'EN')),
            array_keys($publisher->clientBlock(new GameConfig(), null)),
        );
    }

    public function testAnAbsentCountryResolvesAtParityAndSaysSo(): void
    {
        // What every shipped build gets from GET /api/game-config, since none of them sends
        // `?country=`. It must be a complete, usable table rather than an error or an empty one.
        $block = $this->publisher()->clientBlock(new GameConfig(), null)['economyTierBaseline'];

        self::assertNull($block['country']);
        self::assertSame(8, $block['tierCount']);
        self::assertCount(8, $block['tiers']);
    }

    public function testEveryTierCarriesAResolvedScholarshipFigure(): void
    {
        // ADDITIVE, and the per-tier figure is the fix for the flat `youthScholarshipWagePence`
        // being larger than an average bottom-tier wage under the turnover model. The flat key
        // stays at the top level untouched, which is the correct fallback for an older build
        // that does not know tiers have their own.
        $block = $this->publisher()->clientBlock(new GameConfig(), 'EN')['economyTierBaseline'];

        foreach ($block['tiers'] as $row) {
            self::assertArrayHasKey('youthScholarshipPence', $row, "tier {$row['tier']}");
            self::assertSame(
                GameConfig::DEFAULT_YOUTH_SCHOLARSHIP_WAGE_PENCE,
                $row['youthScholarshipPence'],
                'the shipped state must publish the flat figure unchanged',
            );
        }
    }

    public function testTheShippedDefaultsPublishAnUnresolvableTable(): void
    {
        // Inert: the turnover table is unauthored, so every tier reports resolvable false and a
        // zero scale. `resolvable` exists precisely so a client can tell that apart from "this
        // tier is genuinely worth nothing", which a bare 0 cannot express.
        $block = $this->publisher()->clientBlock(new GameConfig(), 'EN')['economyTierBaseline'];

        self::assertSame('legacy', $block['wageModel']);
        self::assertSame(46, $block['weeksPerSeason']);

        foreach ($block['tiers'] as $row) {
            self::assertFalse($row['resolvable'], "tier {$row['tier']}");
            self::assertSame(0, $row['wageScaleMicroPence']);
            self::assertSame(0, $row['turnoverPence']);
        }
    }

    public function testTierIndexEqualsRowIndex(): void
    {
        $tiers = $this->publisher()->clientBlock(new GameConfig(), 'EN')['economyTierBaseline']['tiers'];

        foreach ($tiers as $i => $row) {
            self::assertSame($i + 1, $row['tier'], 'row index must be tier index minus one');
        }
    }

    public function testTheBlockDoesNotLeakACountryFactor(): void
    {
        // The app must never need to know a country factor — publishing one invites the client
        // to apply it a second time to a figure that is already scaled.
        $json = json_encode($this->publisher()->clientBlock(new GameConfig(), 'EN'));

        self::assertIsString($json);
        self::assertStringNotContainsString('countryFactor', $json);
        self::assertStringNotContainsString('combinedFactor', $json);
    }

    public function testTheShippedDefaultsPublishNoTicketPrice(): void
    {
        // INERT, and the zero is the contract: there is no flat ticket price for the app to
        // fall back to, so 0 means "the operator has authored nothing for this division, use
        // your own figure". A published 0 is what keeps a release behaving exactly as it did.
        $tiers = $this->publisher()->clientBlock(new GameConfig(), 'EN')['economyTierBaseline']['tiers'];

        foreach ($tiers as $row) {
            self::assertArrayHasKey('ticketPricePence', $row, "tier {$row['tier']}");
            self::assertSame(0, $row['ticketPricePence'], "tier {$row['tier']}");
        }
    }

    public function testEveryTierCarriesTheRESOLVEDTicketPriceNotTheRawColumn(): void
    {
        // The key already existed and used to be the raw `economyTicketPricePerTierPence` row.
        // It now carries the figure `TicketPriceResolver` resolves — base x ratio x country —
        // so a division the operator never typed a price for still gets one.
        //
        // WHY THAT MATTERS TO THE APP AND NOT ONLY TO THE BACKEND: when this is greater than
        // 0 it is the ADVISED price, the denominator of the elasticity ratio the app measures
        // a club's own price against. Publishing a resolved ladder is therefore how a
        // bottom-tier club stops starting 39% under its own attendance.
        $config = (new GameConfig())
            ->setEconomyTicketPriceTier1Pence(4_000)
            ->setEconomyTicketPriceTierRatioBp([10000, 8000, 6500, 5000, 4000, 3200, 2600, 2200]);

        $tiers = $this->publisher()->clientBlock($config, 'EN')['economyTierBaseline']['tiers'];

        self::assertSame(
            [4_000, 3_200, 2_600, 2_000, 1_600, 1_280, 1_040, 880],
            array_map(static fn (array $row): int => $row['ticketPricePence'], $tiers),
        );
    }

    public function testTheRatioTablesAreOnThePublicBlockAndTheResolvedFiguresAreNot(): void
    {
        // THE SPLIT THIS ENDPOINT PAIR EXISTS FOR. `/api/starter-config` is unauthenticated
        // and has no club to resolve a country from, so it carries the country-INDEPENDENT
        // authoring tables only. Anything resolved and country-scaled belongs on
        // `/api/game-config?country=` and on sync, where a country is in hand.
        $public = $this->publisher()->publicBlock();

        self::assertSame([10000, 10000, 10000, 10000, 10000, 10000, 10000, 10000], $public['sponsorTierRatioBp']);
        self::assertSame([10000, 10000, 10000, 10000, 10000, 10000, 10000, 10000], $public['ticketPriceTierRatioBp']);

        self::assertArrayNotHasKey('ticketPricePence', $public);
        self::assertArrayNotHasKey('sponsorBandsPence', $public);
        self::assertArrayNotHasKey('economyTicketPriceTier1Pence', $public);
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @return array{list<string>, list<string>} */
    private function branchKeys(): array
    {
        $source = $this->syncServiceSource();

        $start  = strpos($source, "'cliqueRelationshipThreshold'");
        self::assertNotFalse($start, 'the gameConfig ternary has moved — this test needs updating');

        $middle = strpos($source, '] : [', (int) $start);
        self::assertNotFalse($middle, 'the ternary separator has moved');

        $end = strpos($source, "\n        ];", (int) $middle);
        self::assertNotFalse($end, 'the end of the fallback branch has moved');

        return [
            $this->keysIn(substr($source, (int) $start, (int) $middle - (int) $start)),
            $this->keysIn(substr($source, (int) $middle, (int) $end - (int) $middle)),
        ];
    }

    /** @return list<string> */
    private function keysIn(string $block): array
    {
        preg_match_all("/^\s*'([a-zA-Z0-9_]+)'\s*=>/m", $block, $m);

        return $m[1];
    }

    private function syncServiceSource(): string
    {
        $file = (new \ReflectionClass(SyncService::class))->getFileName();
        self::assertIsString($file);

        return (string) file_get_contents($file);
    }

    private function publisher(): EconomyConfigPublisher
    {
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new GameConfig());

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn(new \App\Entity\StarterConfig());

        // No profile rows: every country resolves at parity, which is the shipped state.
        $profiles = $this->createStub(CountryProfileRepository::class);

        $scaleResolver = new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profiles);

        return new EconomyConfigPublisher(
            $gameConfigRepo,
            $scaleResolver,
            // A REAL WageModelResolver over the same stubs, because it is final. The publisher
            // needs it for the per-tier youth-scholarship figure.
            new \App\Service\Economy\WageModelResolver(
            $gameConfigRepo,
            $scaleResolver,
            new \App\Service\Economy\TierBandScaleResolver(
                $this->createStub(\App\Repository\StarterConfigRepository::class),
            ),
        ),
            new \App\Service\Economy\SponsorBandResolver($gameConfigRepo, $scaleResolver),
            new \App\Service\Economy\TicketPriceResolver($gameConfigRepo, $scaleResolver),
        );
    }
}
