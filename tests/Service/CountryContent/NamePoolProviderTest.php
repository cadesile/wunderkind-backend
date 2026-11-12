<?php

declare(strict_types=1);

namespace App\Tests\Service\CountryContent;

use App\Entity\Location\CountryProfile;
use App\Enum\Country;
use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\NamePoolProvider;
use App\Service\CountryContent\NamePoolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Override wins, the shipped pool is the floor, and the memo holds.
 *
 * NameGeneratorNationalityCoverageTest guards what SHIPS. This guards the resolved view,
 * which is what every generator actually reads and which did not exist before.
 */
class NamePoolProviderTest extends TestCase
{
    /** A real Country case with no shipped pool — the state this feature exists for. */
    private const POOLLESS = 'Albanian';

    /** Authored in PHP, 150/150. */
    private const AUTHORED = 'English';

    public function testAPoollessCountryHasNothingUntilSomebodyAuthorsOne(): void
    {
        self::assertFalse(
            NamePoolRegistry::has(self::POOLLESS),
            'fixture check: this demonym must ship without a pool',
        );

        self::assertFalse($this->provider(null)->has(self::POOLLESS));
    }

    /** The whole point of the feature. */
    public function testAnOverrideMakesAPoollessCountryNameable(): void
    {
        $provider = $this->provider($this->profileWith('AL', ['Arben', 'Besnik'], ['Hoxha', 'Krasniqi']));

        self::assertTrue($provider->has(self::POOLLESS));
        self::assertSame(['Arben', 'Besnik'], $provider->firstNames(self::POOLLESS));
        self::assertSame(NamePoolProvider::SOURCE_OVERRIDE, $provider->sourceFor(self::POOLLESS, 'firstNames'));
        self::assertSame(NamePoolProvider::SOURCE_OVERRIDE, $provider->sourceFor(self::POOLLESS, 'lastNames'));
        self::assertTrue($provider->hasOverride(self::POOLLESS));
    }

    public function testAnOverrideReplacesTheShippedListRatherThanMergingIt(): void
    {
        $provider = $this->provider($this->profileWith('EN', ['Solo'], ['Only']));

        self::assertSame(['Solo'], $provider->firstNames(self::AUTHORED));
        self::assertNotContains('Smith', $provider->lastNames(self::AUTHORED), 'a union would make a bad shipped name unremovable');
    }

    /**
     * Each list falls back on its own.
     *
     * An operator whose shipped surnames are fine but whose first names repeat should be able
     * to replace one and leave the other alone.
     */
    public function testOverridingOneListLeavesTheOtherOnTheShippedPool(): void
    {
        $provider = $this->provider($this->profileWith('EN', ['Solo'], null));

        self::assertSame(['Solo'], $provider->firstNames(self::AUTHORED));
        self::assertSame(NamePoolRegistry::lastNames(self::AUTHORED), $provider->lastNames(self::AUTHORED));
    }

    public function testNoProfileRowFallsThroughToTheShippedPool(): void
    {
        self::assertSame(
            NamePoolRegistry::firstNames(self::AUTHORED),
            $this->provider(null)->firstNames(self::AUTHORED),
        );
        self::assertSame(NamePoolProvider::SOURCE_REGISTRY, $this->provider(null)->sourceFor(self::AUTHORED, 'firstNames'));
        self::assertFalse($this->provider(null)->hasOverride(self::AUTHORED));
    }

    /** Spoof payloads and pool callers can pass anything; it must fall through, never throw. */
    public function testADemonymNoCountryCarriesFallsThroughInsteadOfThrowing(): void
    {
        $provider = $this->provider(null);

        self::assertSame([], $provider->firstNames('Atlantean'));
        self::assertSame(NamePoolProvider::SOURCE_NONE, $provider->sourceFor('Atlantean', 'firstNames'));
        self::assertSame(NamePoolProvider::SOURCE_NONE, $provider->sourceFor('Atlantean', 'lastNames'));
    }

    /**
     * The two lists carry their own source, and the admin badges depend on it.
     *
     * A single per-country source would have to pick one of two true answers, and the screen
     * would then tell the operator their shipped surnames were theirs to edit.
     */
    public function testEachListReportsItsOwnSource(): void
    {
        $provider = $this->provider($this->profileWith('EN', ['Solo'], null));

        self::assertSame(NamePoolProvider::SOURCE_OVERRIDE, $provider->sourceFor(self::AUTHORED, 'firstNames'));
        self::assertSame(NamePoolProvider::SOURCE_REGISTRY, $provider->sourceFor(self::AUTHORED, 'lastNames'));
        self::assertTrue($provider->hasOverride(self::AUTHORED), 'either list being the operator\'s makes the country overridden');
    }

    /** describe() is what the admin screen renders, so its keys are a contract. */
    public function testDescribeCarriesBothListsAndBothSources(): void
    {
        $described = $this->provider($this->profileWith('AL', ['Arben', 'Besnik'], null))->describe(self::POOLLESS);

        self::assertSame(['Arben', 'Besnik'], $described['firstNames']);
        self::assertSame([], $described['lastNames']);
        self::assertSame(NamePoolProvider::SOURCE_OVERRIDE, $described['firstNamesSource']);
        self::assertSame(NamePoolProvider::SOURCE_NONE, $described['lastNamesSource']);
    }

    /** A pool with surnames and no first names cannot name anybody, so it does not count. */
    public function testHalfAPoolIsNotAPool(): void
    {
        $provider = $this->provider($this->profileWith('AL', ['Arben'], null));

        self::assertFalse($provider->has(self::POOLLESS));
    }

    /**
     * THE TEST THAT MAKES THE PERFORMANCE DECISION A CONTRACT RATHER THAN A COMMENT.
     *
     * A full pool warm reads these ~116,000 times, and MarketPoolService clears the identity
     * map every fifty players, so Doctrine is not caching behind this. One query, whatever
     * the read count.
     */
    public function testTheMemoHoldsAcrossThousandsOfReads(): void
    {
        $repo = $this->createMock(CountryProfileRepository::class);
        $repo->expects($this->once())->method('findByCode')->willReturn(null);

        $provider = new NamePoolProvider($repo);

        for ($i = 0; $i < 2_000; $i++) {
            $provider->firstNames(self::AUTHORED);
            $provider->lastNames(self::AUTHORED);
            $provider->has(self::AUTHORED);
        }
    }

    /** pooledNationalities() must not ask per demonym — that is 71 reads per player. */
    public function testTheCandidateSetCostsOneQueryNotSeventyOne(): void
    {
        $repo = $this->createMock(CountryProfileRepository::class);
        $repo->expects($this->once())->method('namePoolOverrides')->willReturn([]);
        $repo->expects($this->never())->method('findByCode');

        $provider = new NamePoolProvider($repo);

        $first = $provider->pooledNationalities();
        $provider->pooledNationalities();
        $provider->hasMap();

        self::assertContains(self::AUTHORED, $first);
        self::assertNotContains(self::POOLLESS, $first);
        self::assertCount(32, $first, 'the shipped pools, with nothing overridden');
    }

    public function testForgetSendsTheNextReadBackToTheDatabase(): void
    {
        $repo = $this->createMock(CountryProfileRepository::class);
        $repo->expects($this->exactly(2))->method('findByCode')->willReturn(null);

        $provider = new NamePoolProvider($repo);

        $provider->firstNames(self::AUTHORED);
        $provider->forget(self::AUTHORED);
        $provider->firstNames(self::AUTHORED);
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param list<string>|null $first @param list<string>|null $last */
    private function profileWith(string $code, ?array $first, ?array $last): CountryProfile
    {
        $profile = new CountryProfile($code, Country::from($code)->label());
        $profile->setFirstNames($first);
        $profile->setLastNames($last);

        return $profile;
    }

    private function provider(?CountryProfile $profile): NamePoolProvider
    {
        $repo = $this->createStub(CountryProfileRepository::class);
        $repo->method('findByCode')->willReturn($profile);
        $repo->method('namePoolOverrides')->willReturn(
            $profile === null ? [] : [
                $profile->getCode() => [
                    'firstNames' => $profile->getFirstNames(),
                    'lastNames'  => $profile->getLastNames(),
                ],
            ],
        );

        return new NamePoolProvider($repo);
    }
}
