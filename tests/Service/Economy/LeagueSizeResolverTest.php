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
 * WHAT THESE TESTS ARE REALLY PROTECTING is that `League::$tvDeal` — a key shipped builds read
 * as one club's money — does not move on its own. It is derived from the pool, so the divisor
 * is load-bearing for backwards compatibility, not just for correctness: a divisor that changed
 * when somebody generated clubs would silently re-price a tier's TV money for every existing
 * build.
 *
 * So the properties pinned here are: an authored size always wins, a missing pool derives
 * NOTHING (rather than something plausible), and an unresolvable size refuses rather than
 * dividing by a clamped 1 and publishing a whole pool as one club's share.
 *
 * The derivation itself — NPC rows plus the bottom tier's player slot — is checked against the
 * shapes real countries actually have, which is the thing the rule was found to be wrong about
 * for 6 of 15 of them.
 */
class LeagueSizeResolverTest extends TestCase
{
    // ── An authored size wins ─────────────────────────────────────────────

    public function testAuthoredSlotsWinOverAnyCount(): void
    {
        $league = $this->league('EN', 1, slots: 18);

        // 40 NPC rows in the database and the authored size still wins: `slots` exists
        // precisely so the divisor cannot move when somebody generates clubs.
        $resolver = $this->resolver(['EN' => [(string) $league->getId() => 40]], bottomTier: 8);

        self::assertSame(18, $resolver->sizeOf($league));
    }

    public function testZeroSlotsMeansDeriveRatherThanAnEmptyLeague(): void
    {
        // Zero-means-unset, the same idiom as starter_config.club_funding_minimum_pence. A
        // literal reading of 0 would make every pre-migration league's TV share the whole pool.
        $league   = $this->league('EN', 3, slots: 0);
        $resolver = $this->resolver(['EN' => [(string) $league->getId() => 18]], bottomTier: 8);

        self::assertSame(18, $resolver->sizeOf($league));
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
        // CA, JP, NG, PL, US and SC sit at a uniform NPC count, so the rule gives their bottom
        // tier one MORE than the rest. Asserted rather than hidden: it is why `slots` is
        // authored, and the migration backfills from the per-country MAX for exactly this.
        $mid    = $this->league('CA', 3);
        $bottom = $this->league('CA', 8);

        $resolver = $this->resolver([
            'CA' => [(string) $mid->getId() => 8, (string) $bottom->getId() => 8],
        ], bottomTier: 8);

        self::assertSame(8, $resolver->sizeOf($mid));
        self::assertSame(9, $resolver->sizeOf($bottom), 'the known wrong case the stored column fixes');
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

    // ── Deriving the per-club share ───────────────────────────────────────

    public function testNoPoolDerivesNothing(): void
    {
        // THE PROPERTY THAT MAKES THIS SHIPPABLE. Every league starts with a null pool, so
        // nothing derives and `tvDeal` keeps the figure it was authored with — the deploy is a
        // provable no-op.
        $league = $this->league('EN', 1, slots: 18);
        $league->setTvDeal(1_000_000_000);

        $resolver = $this->resolver(['EN' => []], bottomTier: 8);

        self::assertNull($resolver->derivedTvDealPence($league));

        $resolver->syncTvDeal($league);
        self::assertSame(1_000_000_000, $league->getTvDeal(), 'syncTvDeal must not touch an unpooled league');
    }

    public function testAPoolIsSplitEquallyAcrossTheSize(): void
    {
        $league = $this->league('EN', 1, slots: 18);
        $league->setTvPoolPence(18_000_000_000);

        $resolver = $this->resolver(['EN' => []], bottomTier: 8);
        $resolver->syncTvDeal($league);

        self::assertSame(1_000_000_000, $league->getTvDeal());
    }

    public function testAPoolWithNoResolvableSizeRefusesToDivide(): void
    {
        // Mid-bootstrap: the league exists, a pool is authored, no clubs yet. Dividing by a
        // clamped 1 would publish the entire pool as one club's weekly share.
        $league = $this->league('EN', 3, slots: 0);
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
        $league = $this->league('EN', 1, slots: 7);
        $league->setTvPoolPence(100);

        $resolver = $this->resolver(['EN' => []], bottomTier: 8);
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
