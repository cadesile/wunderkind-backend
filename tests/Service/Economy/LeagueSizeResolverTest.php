<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\League;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Service\Economy\LeagueSizeResolver;
use PHPUnit\Framework\TestCase;

/**
 * The TV pool's divisor.
 *
 * ⚠️ **REWRITTEN 2026-11-19 — the owner retired `league.slots`.** These tests used to pin the
 * opposite property, that an authored size always wins, on the grounds that `League::$tvDeal`
 * is a key shipped builds read and a divisor which moved when somebody generated clubs would
 * silently re-price a tier. The owner heard that and ruled the other way: *"there should be no
 * wiring around the amount of clubs, the finances should be dynamic."*
 *
 * So the properties pinned here now are: the size is ALWAYS derived and an authored `slots`
 * cannot influence it, a missing pool derives NOTHING (rather than something plausible), and an
 * unresolvable size refuses rather than dividing by a clamped 1 and publishing a whole pool as
 * one club's share.
 *
 * ⚠️ The first of those is the one with teeth. `testAnAuthoredSlotsIsIgnoredEntirely` is the
 * regression guard for the ruling itself — if it ever goes green again by accident, Scotland
 * silently returns to paying a twelve-club division fourteen ways.
 *
 * The derivation itself — NPC rows plus the bottom tier's player slot — is checked against the
 * shapes real countries actually have, measured off the 2026-11-19 world export.
 */
class LeagueSizeResolverTest extends TestCase
{
    // ── 🔴 THE RULING: the authored column cannot influence the answer ─────

    /**
     * 🔴 **THE REGRESSION GUARD FOR THE OWNER'S RULING, 2026-11-19.** An authored 18 against 40
     * NPC rows must resolve to 40 — the column is not consulted at all. This test is the
     * inverse of the one it replaces (`testAuthoredSlotsWinOverAnyCount`), deliberately so:
     * the two cannot both pass, and a change that restores the old behaviour fails here rather
     * than quietly reverting the decision.
     */
    public function testAnAuthoredSlotsIsIgnoredEntirely(): void
    {
        $league   = $this->league('EN', 1, slots: 18);
        $resolver = $this->resolver(['EN' => [(string) $league->getId() => 40]], bottomTier: 8);

        self::assertSame(40, $resolver->sizeOf($league), 'league.slots was read back');
    }

    /**
     * ⚠️ **SCOTLAND AS DEPLOYED, which is the case the ruling was made for.** `slots` 14 at
     * every tier against 12 NPC rows at tiers 1-7 and 11 at tier 8 (world export,
     * 2026-11-19). Both tiers must come out at **12**, not 14: that is the 14 -> 12 move, and
     * at the bottom tier it is 11 rows plus the player's own place.
     */
    public function testTheDeployedScotlandShapeResolvesToTwelveAtEveryTier(): void
    {
        $mid    = $this->league('SC', 3, slots: 14);
        $bottom = $this->league('SC', 8, slots: 14);

        $resolver = $this->resolver([
            'SC' => [(string) $mid->getId() => 12, (string) $bottom->getId() => 11],
        ], bottomTier: 8);

        self::assertSame(12, $resolver->sizeOf($mid));
        self::assertSame(12, $resolver->sizeOf($bottom));
    }

    /**
     * ⚠️ **ENGLAND AS DEPLOYED, and the half that must NOT move.** `slots` 16 at every tier
     * against 16 NPC rows at tiers 1-7 and 15 at tier 8. The derivation reproduces the authored
     * 16 at all eight tiers, which is why retiring the column changed no English figure.
     */
    public function testTheDeployedEnglandShapeReproducesItsAuthoredSixteen(): void
    {
        $mid    = $this->league('EN', 3, slots: 16);
        $bottom = $this->league('EN', 8, slots: 16);

        $resolver = $this->resolver([
            'EN' => [(string) $mid->getId() => 16, (string) $bottom->getId() => 15],
        ], bottomTier: 8);

        self::assertSame(16, $resolver->sizeOf($mid));
        self::assertSame(16, $resolver->sizeOf($bottom));
    }

    // ── The derivation, against real country shapes ───────────────────────

    public function testTheBottomTierGetsThePlayersSlot(): void
    {
        // England's real shape on dev: 18 NPC rows at tiers 1-7, 17 at tier 8 — the missing one
        // is the player's club. So both come out at 18, which is what makes the rule work for
        // the nine properly-bootstrapped countries.
        $mid    = $this->league('EN', 3);
        $bottom = $this->league('EN', 8);

        $resolver = $this->resolver([
            'EN' => [(string) $mid->getId() => 18, (string) $bottom->getId() => 17],
        ], bottomTier: 8);

        self::assertSame(18, $resolver->sizeOf($mid));
        self::assertSame(18, $resolver->sizeOf($bottom));
    }

    public function testAUniformCountryComesOutOneLargerAtTheBottom(): void
    {
        // A country whose deepest tier holds the SAME count as the rest — no gap was left for
        // the player — still gets the +1, because the player's club joins that division either
        // way and the table it fields is `rows + 1`. Asserted rather than hidden: it is the one
        // assumption left in the derivation, and `app:economy:wage-table`'s check 9 reports the
        // shape (a deepest tier not one row short of the tier above) so a human can see it.
        // ⚠️ No country in the 2026-11-19 export is in this shape — all 25 reserve a place.
        $mid    = $this->league('CA', 3);
        $bottom = $this->league('CA', 8);

        $resolver = $this->resolver([
            'CA' => [(string) $mid->getId() => 8, (string) $bottom->getId() => 8],
        ], bottomTier: 8);

        self::assertSame(8, $resolver->sizeOf($mid));
        self::assertSame(9, $resolver->sizeOf($bottom), 'the player joins the deepest division either way');
    }

    public function testTheBottomTierIsTheDEEPESTEXISTINGONE(): void
    {
        // Not country_profile.league_tier_count. A new club is placed by
        // findLowestTierForCountry(), so "where the player lands" and "which league gets the
        // extra slot" must be the same question — a declared depth of 8 with only 4 leagues
        // built would hand the slot to a tier that does not exist.
        $tier4 = $this->league('SC', 4);

        $resolver = $this->resolver(['SC' => [(string) $tier4->getId() => 3]], bottomTier: 4);

        self::assertSame(4, $resolver->sizeOf($tier4));
    }

    public function testAnEmptyNonBottomTierIsZeroRatherThanOne(): void
    {
        // Honest, so derivedTvDealPence() can refuse. A clamped 1 here would publish a whole
        // pool as one club's share for a tier nobody has generated yet.
        $league   = $this->league('EN', 3);
        $resolver = $this->resolver(['EN' => []], bottomTier: 8);

        self::assertSame(0, $resolver->sizeOf($league));
    }

    /**
     * ⚠️ **AND AN EMPTY BOTTOM TIER IS ALSO 0, NOT 1 — the guard the unconditional `+1` needs.**
     *
     * Without it the player's place turns a tier with no clubs into a division of ONE and
     * `derivedTvDealPence()` publishes the whole pool as a single club's share: the same
     * "divide by a clamped 1" defect the test above exists against, reached from the other side.
     * `BootstrapCountryCommand` creates a country's leagues and then fills them, so every
     * country passes through exactly this state. `EconomyTvCountryScalingGuardTest` caught it on
     * a real fixture the hour `slots` stopped being read, which is why it is pinned here too.
     */
    public function testAnEmptyBOTTOMTierIsAlsoZeroRatherThanThePlayerAlone(): void
    {
        $league = $this->league('EN', 8);
        $league->setTvPoolPence(2_448_000_000);

        $resolver = $this->resolver(['EN' => []], bottomTier: 8);

        self::assertSame(0, $resolver->sizeOf($league), 'an empty division was given the player as its only club');
        self::assertNull($resolver->derivedTvDealPence($league), 'the whole pool was published as one share');
    }

    // ── Deriving the per-club share ───────────────────────────────────────

    public function testNoPoolDerivesNothing(): void
    {
        // THE PROPERTY THAT MAKES THIS SHIPPABLE. Every league starts with a null pool, so
        // nothing derives and `tvDeal` keeps the figure it was authored with — the deploy is a
        // provable no-op.
        $league = $this->league('EN', 1);
        $league->setTvDeal(1_000_000_000);

        $resolver = $this->resolver(['EN' => []], bottomTier: 8);

        self::assertNull($resolver->derivedTvDealPence($league));

        $resolver->syncTvDeal($league);
        self::assertSame(1_000_000_000, $league->getTvDeal(), 'syncTvDeal must not touch an unpooled league');
    }

    public function testAPoolIsSplitEquallyAcrossTheSize(): void
    {
        $league = $this->league('EN', 1);
        $league->setTvPoolPence(18_000_000_000);

        // ⚠️ The size comes from the ROWS now, so the rows are what this fixture authors. It
        // used to pass `slots: 18` against an empty count — which after the 2026-11-19 ruling
        // resolves to 0 and derives nothing, so the arithmetic would never have run.
        $resolver = $this->resolver(['EN' => [(string) $league->getId() => 18]], bottomTier: 8);
        $resolver->syncTvDeal($league);

        self::assertSame(1_000_000_000, $league->getTvDeal());
    }

    public function testAPoolWithNoResolvableSizeRefusesToDivide(): void
    {
        // Mid-bootstrap: the league exists, a pool is authored, no clubs yet. Dividing by a
        // clamped 1 would publish the entire pool as one club's weekly share.
        $league = $this->league('EN', 3);
        $league->setTvPoolPence(900_000_000);
        $league->setTvDeal(50_000_000);

        $resolver = $this->resolver(['EN' => []], bottomTier: 8);

        self::assertNull($resolver->derivedTvDealPence($league));

        $resolver->syncTvDeal($league);
        self::assertSame(50_000_000, $league->getTvDeal(), 'the authored share stands until a size exists');
    }

    public function testTheSplitFloorsRatherThanRounding(): void
    {
        // intdiv, so the sum of the shares never EXCEEDS the pool. A rounded-up share would let
        // a league pay out more than it has, which is the direction that matters.
        $league = $this->league('EN', 1);
        $league->setTvPoolPence(100);

        $resolver = $this->resolver(['EN' => [(string) $league->getId() => 7]], bottomTier: 8);
        $resolver->syncTvDeal($league);

        self::assertSame(14, $league->getTvDeal());
        self::assertLessThanOrEqual(100, $league->getTvDeal() * 7);
    }

    // ── Caching ───────────────────────────────────────────────────────────

    public function testCountsAreFetchedOncePerCountry(): void
    {
        // A season pyramid resolves all eight tiers in one request; without the cache that is
        // eight count queries.
        $npcRepo = $this->createMock(NpcClubRepository::class);
        $npcRepo->expects(self::once())->method('countsByLeagueForCountry')->willReturn([]);

        $leagueRepo = $this->createStub(LeagueRepository::class);
        $leagueRepo->method('findLowestTierForCountry')->willReturn($this->league('EN', 8));

        $resolver = new LeagueSizeResolver($npcRepo, $leagueRepo);

        for ($tier = 1; $tier <= 8; $tier++) {
            $resolver->sizeOf($this->league('EN', $tier));
        }
    }

    public function testForgetReReadsTheCounts(): void
    {
        // Needed because a bootstrap creates leagues, fills them, and only then has a real size
        // to author — all inside one request.
        $npcRepo = $this->createMock(NpcClubRepository::class);
        $npcRepo->expects(self::exactly(2))->method('countsByLeagueForCountry')->willReturn([]);

        $leagueRepo = $this->createStub(LeagueRepository::class);
        $leagueRepo->method('findLowestTierForCountry')->willReturn(null);

        $resolver = new LeagueSizeResolver($npcRepo, $leagueRepo);
        $league   = $this->league('EN', 1);

        $resolver->sizeOf($league);
        $resolver->forget('EN');
        $resolver->sizeOf($league);
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    private function league(string $country, int $tier, int $slots = 0): League
    {
        $league = new League($country, $tier, "League {$tier}");
        $league->setSlots($slots);

        return $league;
    }

    /** @param array<string, array<string, int>> $counts country => (league id => npc count) */
    private function resolver(array $counts, int $bottomTier): LeagueSizeResolver
    {
        $npcRepo = $this->createStub(NpcClubRepository::class);
        $npcRepo->method('countsByLeagueForCountry')->willReturnCallback(
            static fn (string $country) => $counts[strtoupper($country)] ?? [],
        );

        $leagueRepo = $this->createStub(LeagueRepository::class);
        $leagueRepo->method('findLowestTierForCountry')->willReturnCallback(
            fn (string $country) => $this->league($country, $bottomTier),
        );

        return new LeagueSizeResolver($npcRepo, $leagueRepo);
    }
}
