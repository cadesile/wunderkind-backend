<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Scout;
use App\Service\MarketDataService;
use App\Service\WorldPackSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * `ability` reaches the app on EVERY scout-carrying payload.
 *
 * A26's deliverable is not "the column exists" — it is that the app can read the figure instead
 * of re-deriving it, which only holds if every payload actually carries it. The two that matter
 * are the live market (`GET /api/market/data`, via `MarketDataService::serializeScout`) and the
 * cached world pack (`WorldPackSnapshotBuilder::buildScoutSnapshot`, which the starter pack also
 * goes through). The pack's full key ORDER is pinned separately by
 * `WorldInitializationTierPackAgentTest`; this file pins the thing that would actually break the
 * app — the key being present and carrying the stored value — on both paths at once.
 *
 * ⚠️ **It also pins what must NOT be there.** The pack carries no `weeklySalary`: that is money,
 * and the pack-is-a-census rule keeps money out. `ability` is census. The distinction is the
 * whole argument for `WORLD_PACK_VERSION` 12 being legitimate, so if someone later "tidies" a
 * wage into the snapshot this fails rather than silently re-breaking the rule.
 */
final class ScoutAbilityPublishedTest extends TestCase
{
    private const REAL_JUDGEMENTS = [
        'potential'   => 61,
        'technical'   => 47,
        'physical'    => 23,
        'mental'      => 88,
        'personality' => 34,
    ];

    public function testTheWorldPackScoutSnapshotCarriesAbility(): void
    {
        $snapshot = (new WorldPackSnapshotBuilder())->buildScoutSnapshot($this->scout());

        self::assertArrayHasKey('ability', $snapshot);
        self::assertSame(42, $snapshot['ability'], 'the STORED figure, not a re-derivation');
    }

    public function testTheMarketScoutPayloadCarriesAbility(): void
    {
        $service = (new \ReflectionClass(MarketDataService::class))->newInstanceWithoutConstructor();
        $method  = new \ReflectionMethod($service, 'serializeScout');

        $payload = $method->invoke($service, $this->scout());

        self::assertArrayHasKey('ability', $payload);
        self::assertSame(42, $payload['ability']);
    }

    /**
     * `experience` and `ability` must both be published, and as DIFFERENT numbers. The defect
     * A26 removes is three sites reading one as the other, so a payload that carried only
     * `experience` — or that happened to publish the same value twice — would let the conflation
     * back in without any test noticing.
     */
    public function testExperienceAndAbilityAreBothPublishedAndAreDistinct(): void
    {
        $scout    = $this->scout();
        $snapshot = (new WorldPackSnapshotBuilder())->buildScoutSnapshot($scout);

        self::assertSame(17, $snapshot['experience'], 'career length');
        self::assertSame(42, $snapshot['ability'], 'quality');
        self::assertNotSame(
            $snapshot['experience'],
            $snapshot['ability'],
            'these are different quantities and this fixture is chosen so they cannot coincide',
        );
    }

    /**
     * `tier` stays derived from `experience`, deliberately. Re-pointing it at `ability` would be
     * a changed value under an unchanged key — a breaking change by
     * `.context/shared/api-backwards-compatibility.md`'s own definition — so it is pinned here
     * rather than left to a reader's judgement.
     */
    public function testTierIsStillDerivedFromExperienceNotAbility(): void
    {
        $snapshot = (new WorldPackSnapshotBuilder())->buildScoutSnapshot($this->scout());

        $fromExperience = \App\Enum\Tier::fromScore(17)->value;
        $fromAbility    = \App\Enum\Tier::fromScore(42)->value;

        self::assertSame($fromExperience, $snapshot['tier']);
        self::assertNotSame($fromAbility, $snapshot['tier'], 'the fixture separates the two tiers');
    }

    /** The pack carries no money — see the class docblock. */
    public function testTheWorldPackScoutSnapshotStillCarriesNoWage(): void
    {
        $snapshot = (new WorldPackSnapshotBuilder())->buildScoutSnapshot($this->scout());

        self::assertArrayNotHasKey('weeklySalary', $snapshot);
    }

    /**
     * Experience 17 and ability 42 are deliberately far apart, and 42 is deliberately NOT what
     * the formula would give for these inputs — so a payload that re-derived instead of reading
     * the column would fail these tests.
     */
    private function scout(): Scout
    {
        $scout = new Scout('Pat Example');
        $scout->setExperience(17);
        $scout->setJudgements(self::REAL_JUDGEMENTS);
        $scout->setAbility(42);

        return $scout;
    }
}
