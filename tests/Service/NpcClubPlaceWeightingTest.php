<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\FacilityTemplate;
use App\Entity\GameConfig;
use App\Enum\CitySize;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\Location\LocationRepository;
use App\Repository\NpcClubRepository;
use App\Service\LeagueService;
use App\Service\Location\LocationCatalogueService;
use App\Service\NpcClubGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * WHICH town a generated club plays in — the half of generation that used to be a coin toss.
 *
 * THE BUG THESE PIN. A country's places are banded BIG / MEDIUM / SMALL by population
 * percentile, and the generator then picked inside a band with every member on equal odds.
 * England bands its top 20% as BIG, which is 57 of its 284 towns, so London and Poole had
 * identical claims on a top-flight club and a five-club England contained London about 6% of
 * the time. The observed result was a top division of Bolton, Oxford, Plymouth, Poole and
 * Southampton — all correctly BIG, none of them England's biggest.
 *
 * TWO FIXES, TESTED SEPARATELY because they are independent and either could be reverted:
 *   - tier 1 is ANCHORED to the capital and the five biggest towns (anchorPlaces())
 *   - within a band the draw is weighted by sqrt(population) rather than flat
 *     (pickWeightedPlace())
 *
 * Deliberately NOT pinned: the exact probabilities. They move with the share percentages, the
 * tier weights and the town set, none of which this file owns. What is pinned is ordering —
 * bigger towns beat smaller ones in the same band — which is the property that was wrong.
 */
class NpcClubPlaceWeightingTest extends TestCase
{
    /** England's shape: the capital is also the largest town. */
    private const CAPITAL_IS_LARGEST = 0;

    /** Nigeria's shape: Abuja is the capital, Lagos is the largest. */
    private const CAPITAL_IS_EIGHTH = 7;

    /**
     * @param array<string, true> $takenLocationIds towns that already have a tier-1 club
     */
    private function makeService(
        int   $capitalIndex = self::CAPITAL_IS_LARGEST,
        array $takenLocationIds = [],
        bool  $withIds = false,
        bool  $priced = true,
    ): NpcClubGenerationService {
        $clubRepo = $this->createStub(NpcClubRepository::class);
        $clubRepo->method('findLocationIdsWithClubInTier')->willReturnCallback(
            static function (array $ids, int $tier) use ($takenLocationIds): array {
                return array_intersect_key($takenLocationIds, array_flip($ids));
            },
        );

        $template = $this->createStub(FacilityTemplate::class);
        $template->method('getSlug')->willReturn('training_pitch');
        $facilityRepo = $this->createStub(FacilityTemplateRepository::class);
        $facilityRepo->method('findBy')->willReturn([$template]);

        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new GameConfig());

        return new NpcClubGenerationService(
            $this->createStub(EntityManagerInterface::class),
            $facilityRepo,
            $clubRepo,
            $this->createStub(LeagueService::class),
            $gameConfigRepo,
            $this->makeCatalogue($capitalIndex, $withIds, $priced),
            $this->createStub(CountryProfileRepository::class),
        );
    }

    /**
     * Forty towns on a steep population curve, the shape a seeded country actually has.
     *
     * STEEP ON PURPOSE. A flat spread would let the anchors pass while the sqrt weighting did
     * nothing measurable; 2m down to ~30k over forty towns is the real spread, and it is what
     * makes "bigger wins inside a band" an assertion rather than a coin toss.
     */
    /**
     * A service whose Nth town is priced at $pence instead of its place on the curve.
     *
     * Lets a test move one town's land value without moving its population, which is the only
     * way to show that the price is what club strength reads.
     */
    private function makeServiceWithLandOverride(int $index, int $pence): NpcClubGenerationService
    {
        $clubRepo = $this->createStub(NpcClubRepository::class);
        $clubRepo->method('findLocationIdsWithClubInTier')->willReturn([]);

        $template = $this->createStub(FacilityTemplate::class);
        $template->method('getSlug')->willReturn('training_pitch');
        $facilityRepo = $this->createStub(FacilityTemplateRepository::class);
        $facilityRepo->method('findBy')->willReturn([$template]);

        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new GameConfig());

        return new NpcClubGenerationService(
            $this->createStub(EntityManagerInterface::class),
            $facilityRepo,
            $clubRepo,
            $this->createStub(LeagueService::class),
            $gameConfigRepo,
            $this->makeCatalogue(self::CAPITAL_IS_LARGEST, false, true, [$index => $pence]),
            $this->createStub(CountryProfileRepository::class),
        );
    }

    /** @param array<int, int> $landOverrides town index => pence, overriding the default curve */
    private function makeCatalogue(int $capitalIndex, bool $withIds, bool $priced = true, array $landOverrides = []): LocationCatalogueService
    {
        $repo = $this->createStub(LocationRepository::class);
        $repo->method('findForGeneration')->willReturnCallback(
            static function () use ($capitalIndex, $withIds, $priced, $landOverrides): array {
                $places = [];

                for ($i = 0; $i < 40; $i++) {
                    $place = [
                        'name'            => 'Town' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                        'population_size' => self::populationOf($i),
                        'region'          => 'R',
                        'is_capital'      => $i === $capitalIndex,
                        'city_size'       => 'SMALL',
                        // Land tracks population but not proportionally, which is the point:
                        // the standings must come from the price, not from a restatement of
                        // the population ranking.
                        'land_pence'      => $priced ? ($landOverrides[$i] ?? self::landPenceOf($i)) : null,
                    ];

                    if ($withIds) {
                        $place['id'] = self::locationId($i);
                    }

                    $places[] = $place;
                }

                return $places;
            },
        );

        return new LocationCatalogueService($repo);
    }

    /** A stable fake location id for the Nth town, so a test can mark one as taken. */
    private static function locationId(int $i): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $i);
    }

    /** What one plot in the Nth town costs — the measure club strength is drawn from. */
    private static function landPenceOf(int $i): int
    {
        return (int) round(15_000_000_00 * (0.85 ** $i));
    }

    /** The population the Nth town is generated with — the key this file identifies towns by. */
    private static function populationOf(int $i): int
    {
        return (int) round(2_000_000 * (0.9 ** $i));
    }

    /**
     * The town each club plays in, in generation order.
     *
     * VIA POPULATION, NOT THE CLUB NAME. A club is named "{town} {suffix}" in most countries
     * and "{suffix} {town}" in Polish, with the suffix drawn from a per-country list — so
     * unpicking the town out of the name means knowing every suffix of every country the test
     * touches, and it silently broke the moment this file used NG. NpcClub records the town's
     * population verbatim at generation, and the fixture's populations are distinct, so that
     * is an exact reverse lookup.
     *
     * @return list<string>
     */
    private function townsOf(array $clubs): array
    {
        $byPopulation = [];
        for ($i = 0; $i < 40; $i++) {
            $byPopulation[self::populationOf($i)] = 'Town' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        }

        return array_map(
            static fn ($club): string => $byPopulation[$club->getPopulationSize()] ?? '?' . $club->getPopulationSize(),
            $clubs,
        );
    }

    // ── Anchoring ────────────────────────────────────────────────────────────

    public function testTierOneIsSeededWithTheFiveBiggestTowns(): void
    {
        $clubs = $this->makeService()->generateClubs(5, 1, 'EN');

        self::assertSame(
            ['Town00', 'Town01', 'Town02', 'Town03', 'Town04'],
            $this->townsOf($clubs),
            'the top division must open with the country\'s five biggest towns, in order',
        );
    }

    public function testTheCapitalIsAnchoredEvenWhenItIsNotTheLargestTown(): void
    {
        $clubs = $this->makeService(self::CAPITAL_IS_EIGHTH)->generateClubs(5, 1, 'NG');
        $towns = $this->townsOf($clubs);

        self::assertSame('Town07', $towns[0], 'the capital comes first, ahead of the largest town');
        self::assertSame(
            ['Town07', 'Town00', 'Town01', 'Town02', 'Town03'],
            $towns,
            'then the biggest towns, with the fifth-biggest pushed out by the capital',
        );
    }

    public function testTheCapitalAppearsOnceWhenItIsAlsoTheLargest(): void
    {
        $towns = $this->townsOf($this->makeService()->generateClubs(5, 1, 'EN'));

        self::assertSame(['Town00'], array_values(array_filter($towns, static fn ($t) => $t === 'Town00')));
    }

    public function testFewerClubsThanAnchorsTakesThemInOrder(): void
    {
        $clubs = $this->makeService()->generateClubs(2, 1, 'EN');

        self::assertSame(['Town00', 'Town01'], $this->townsOf($clubs));
    }

    public function testClubsBeyondTheAnchorsFallBackToTheWeightedDraw(): void
    {
        $towns = $this->townsOf($this->makeService()->generateClubs(8, 1, 'EN'));

        self::assertSame(['Town00', 'Town01', 'Town02', 'Town03', 'Town04'], array_slice($towns, 0, 5));
        self::assertCount(8, $towns, 'the remaining three still get a town');
    }

    public function testATownThatAlreadyHasATierOneClubIsNotAnchoredAgain(): void
    {
        // Town00 and Town02 already have a tier-1 club, so the anchors slide to the next biggest.
        $service = $this->makeService(
            self::CAPITAL_IS_LARGEST,
            [self::locationId(0) => true, self::locationId(2) => true],
            withIds: true,
        );

        self::assertSame(
            ['Town01', 'Town03', 'Town04'],
            $this->townsOf($service->generateClubs(3, 1, 'EN')),
        );
    }

    /**
     * ONLY THE TOP DIVISION IS ANCHORED. Tier 2 keeps the weighted draw, so forcing it to the
     * same five towns every time would be a regression, not a feature — asserted over enough
     * runs that an unlucky-but-legal draw cannot fail it.
     */
    public function testLowerTiersAreNotAnchored(): void
    {
        $service = $this->makeService();
        $opening = [];

        for ($run = 0; $run < 12; $run++) {
            $opening[] = $this->townsOf($service->generateClubs(5, 2, 'EN'))[0];
        }

        self::assertGreaterThan(
            1,
            count(array_unique($opening)),
            'tier 2 must still vary its towns between runs',
        );
    }

    // ── Club strength within a band ──────────────────────────────────────────

    /**
     * The second flattening: two BIG towns, the same tier, indistinguishable clubs.
     *
     * reputationForTier() and balanceForTier() narrowed the tier's range by city size and then
     * rolled uniformly inside it, so every BIG town in tier 1 drew from one identical range —
     * 57 towns in England's case. A club in a more valuable town must now be the stronger and
     * the richer of the two.
     *
     * AVERAGED, NOT A SINGLE PAIR. Town00 and Town04 are the first and fifth of EIGHT towns in
     * the BIG band, not its extremes, so their windows overlap across most of their width and
     * one roll can land either way — an earlier draft of this test compared a single pair and
     * failed about one run in three. The claim the implementation actually makes is about the
     * trend.
     */
    public function testTheMoreValuableTownGetsAStrongerAndRicherClub(): void
    {
        $service = $this->makeService();
        $totals  = ['Town00' => ['rep' => 0, 'bal' => 0], 'Town04' => ['rep' => 0, 'bal' => 0]];

        for ($run = 0; $run < 40; $run++) {
            $clubs = $service->generateClubs(5, 1, 'EN');

            foreach (array_combine($this->townsOf($clubs), $clubs) as $town => $club) {
                if (isset($totals[$town])) {
                    $totals[$town]['rep'] += $club->getReputation();
                    $totals[$town]['bal'] += $club->getBalance();
                }
            }
        }

        self::assertGreaterThan(
            $totals['Town04']['rep'],
            $totals['Town00']['rep'],
            'the club in the more valuable town must out-rank the one in the less valuable',
        );
        self::assertGreaterThan(
            $totals['Town04']['bal'],
            $totals['Town00']['bal'],
            'and must be the richer of the two',
        );
    }

    /**
     * Over many runs, not one: the windows are half the range wide and still random inside,
     * so a single comparison between two towns can legitimately go either way.
     *
     * EVERY SECOND TOWN, NOT EVERY TOWN, and the reason is arithmetic rather than caution.
     * Tier 1's BIG envelope is 13 reputation points wide and the band holds eight towns, so
     * the window's integer offset has roughly six distinct positions to hand out: ADJACENT
     * towns routinely round onto the SAME window, and two towns drawing from one identical
     * range differ only by sampling noise. Asserting an order between those would be
     * asserting noise. Town00, Town02 and Town04 do land on distinct windows, and that
     * ordering is a real guarantee.
     */
    public function testReputationTrendsDownwardAcrossTheAnchoredTowns(): void
    {
        $service = $this->makeService();
        $totals  = ['Town00' => 0, 'Town02' => 0, 'Town04' => 0];

        for ($run = 0; $run < 60; $run++) {
            $clubs = $service->generateClubs(5, 1, 'EN');
            foreach (array_combine($this->townsOf($clubs), $clubs) as $town => $club) {
                if (isset($totals[$town])) {
                    $totals[$town] += $club->getReputation();
                }
            }
        }

        self::assertGreaterThan($totals['Town02'], $totals['Town00']);
        self::assertGreaterThan($totals['Town04'], $totals['Town02']);
    }

    /**
     * LAND VALUE, NOT POPULATION, when the town has a price.
     *
     * The fixture's land values fall faster than its populations, so the two orderings agree
     * on rank but not on spacing. This pins that the price is what is read: a town priced out
     * of line with its population takes its club strength from the price.
     */
    public function testAnUnderpricedTownGetsAWeakerClubThanItsPopulationWouldSuggest(): void
    {
        $service = $this->makeService();

        // Town01 is the second-biggest and second-most-valuable. Re-price it to the bottom of
        // the BIG band and its club should fall with it.
        $cheap = $this->makeServiceWithLandOverride(1, self::landPenceOf(7));

        $strongWhenValuable = 0;
        $strongWhenCheap    = 0;

        for ($run = 0; $run < 30; $run++) {
            $byTown = array_combine(
                $this->townsOf($clubs = $service->generateClubs(5, 1, 'EN')),
                $clubs,
            );
            $strongWhenValuable += $byTown['Town01']->getReputation();

            $byTown = array_combine(
                $this->townsOf($clubs = $cheap->generateClubs(5, 1, 'EN')),
                $clubs,
            );
            $strongWhenCheap += $byTown['Town01']->getReputation();
        }

        self::assertGreaterThan(
            $strongWhenCheap,
            $strongWhenValuable,
            'the same town, same population, cheaper land — its club must be weaker',
        );
    }

    /** A country with no land prices yet must still rank its towns, by population. */
    public function testAnUnpricedCountryFallsBackToPopulation(): void
    {
        $service = $this->makeService(priced: false);
        $totals  = ['Town00' => 0, 'Town04' => 0];

        for ($run = 0; $run < 30; $run++) {
            $byTown = array_combine($this->townsOf($clubs = $service->generateClubs(5, 1, 'EN')), $clubs);
            $totals['Town00'] += $byTown['Town00']->getReputation();
            $totals['Town04'] += $byTown['Town04']->getReputation();
        }

        self::assertGreaterThan($totals['Town04'], $totals['Town00']);
    }

    // ── Within-band weighting ────────────────────────────────────────────────

    /**
     * The regression in its purest form: two towns in the SAME band, one far bigger.
     *
     * TEN TOWNS, NOT TWO. A two-town country bands one BIG and one SMALL, which puts each
     * alone in its own band — and a band of one divides the whole band weight to that one
     * town whatever the weighting rule is, so the test passes 50/50 by construction and
     * measures nothing. An earlier draft of this file did exactly that. Ten towns gives a
     * BIG cutoff of ceil(10 × 0.20) = 2, which is the smallest fixture where "inside a band"
     * means anything at all.
     *
     * The other bands are weighted to zero so the draw is confined to the pair under test;
     * pickWeightedPlace floors each place at 0.0001, so they stay reachable and simply never
     * come up over this many rolls.
     */
    public function testInsideOneBandTheBiggerTownIsPickedMoreOften(): void
    {
        $service = $this->makeService();
        $places  = [
            ['name' => 'Huge',  'population_size' => 9_000_000, 'region' => 'R'],
            ['name' => 'Large', 'population_size' =>   150_000, 'region' => 'R'],
        ];
        for ($i = 0; $i < 8; $i++) {
            $places[] = ['name' => 'Rest' . $i, 'population_size' => 140_000 - ($i * 1_000), 'region' => 'R'];
        }

        $classified = $service->classifyPlaces($places);
        $bands      = [];
        foreach ($classified as $place) {
            $bands[$place['name']] = $place['city_size'];
        }

        self::assertSame(CitySize::BIG, $bands['Huge'],  'fixture check: Huge must band BIG');
        self::assertSame(CitySize::BIG, $bands['Large'], 'fixture check: Large must band BIG too');

        $huge = 0;
        for ($i = 0; $i < 400; $i++) {
            if ($service->pickWeightedPlace($classified, ['big' => 100, 'medium' => 0, 'small' => 0])['name'] === 'Huge') {
                $huge++;
            }
        }

        // Flat weighting splits a two-town band 50/50; sqrt puts Huge near 89%.
        self::assertGreaterThan(280, $huge);
    }

    /**
     * The real shape: one dominant city among many in the same BIG band.
     *
     * Forty towns band to eight BIG. Flat weighting gave the largest exactly 1/8 of the BIG
     * draw; sqrt weighting should put it clearly ahead of the eighth-biggest, which is the
     * ordering that was broken.
     */
    public function testTheLargestTownOutdrawsTheSmallestInItsOwnBand(): void
    {
        $service    = $this->makeService();
        $classified = $service->classifyPlaces(
            $this->makeCatalogue(self::CAPITAL_IS_LARGEST, false, false)->placesForGeneration('EN'),
        );

        $big = array_values(array_filter($classified, static fn (array $p) => $p['city_size'] === CitySize::BIG));
        self::assertGreaterThan(2, count($big), 'fixture check: need a BIG band worth measuring');

        $largest  = $big[0]['name'];
        $smallest = $big[array_key_last($big)]['name'];

        $counts = [$largest => 0, $smallest => 0];
        for ($i = 0; $i < 600; $i++) {
            $name = $service->pickWeightedPlace($classified, ['big' => 100, 'medium' => 0, 'small' => 0])['name'];
            if (isset($counts[$name])) {
                $counts[$name]++;
            }
        }

        self::assertGreaterThan(
            $counts[$smallest],
            $counts[$largest],
            "{$largest} must outdraw {$smallest} despite sharing the BIG band",
        );
    }

    /** A town with no population recorded must stay reachable rather than drop to zero weight. */
    public function testAPlaceWithNoPopulationIsStillPickable(): void
    {
        $service    = $this->makeService();
        $classified = $service->classifyPlaces([
            ['name' => 'Known',   'population_size' => 500_000, 'region' => 'R'],
            ['name' => 'Unknown', 'population_size' => 0,       'region' => 'R'],
        ]);

        $seen = [];
        for ($i = 0; $i < 600; $i++) {
            $seen[$service->pickWeightedPlace($classified, ['big' => 50, 'medium' => 50, 'small' => 50])['name']] = true;
        }

        self::assertArrayHasKey('Unknown', $seen);
    }
}
