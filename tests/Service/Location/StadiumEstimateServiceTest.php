<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Entity\StarterConfig;
use App\Repository\Location\StadiumEstimateInputRepository;
use App\Repository\Location\StadiumEstimateRepository;
use App\Service\Location\StadiumEstimateService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Stadium pricing: that it is deterministic, that it is affordable, and that a quote cannot be
 * destroyed underneath a hold.
 *
 * The affordability test is the one worth reading. A stadium price is only useful if a player can
 * evaluate it against what they have, and the first version of this model — calibrated on real
 * construction costs — priced London's ground at 1,350x a club's entire starting balance. The
 * figure was not expensive, it was meaningless, and nothing in the schema or the type system
 * would ever have objected.
 */
class StadiumEstimateServiceTest extends KernelTestCase
{
    private const COUNTRY = 'ZS';

    private EntityManagerInterface $em;
    private Connection $connection;
    private StadiumEstimateService $service;
    private StadiumEstimateRepository $estimates;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->service    = self::getContainer()->get(StadiumEstimateService::class);
        $this->estimates  = self::getContainer()->get(StadiumEstimateRepository::class);

        $this->purge();

        $this->em->persist(new CountryProfile(self::COUNTRY, 'Stadiumland'));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM stadium_estimate WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY],
        );
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::COUNTRY]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::COUNTRY]);
    }

    private function town(string $name, ?int $population, ?int $landPence): Location
    {
        $country  = $this->em->find(CountryProfile::class, self::COUNTRY);
        $location = new Location($country, $name, strtolower(self::COUNTRY . '-' . $name), strtolower($name));
        $location->setRegion('R');
        $location->setPopulation($population);
        $location->setLandPence($landPence);

        $this->em->persist($location);
        $this->em->flush();

        return $location;
    }

    // ───────────────────────── the model ─────────────────────────

    /**
     * The figures must be plausible as REAL BUILD COSTS — not as things a new club can afford.
     *
     * There is deliberately no affordability assertion here, and the earlier version of this
     * test had the design backwards. The gap between what a ground costs and what a club has IS
     * the progression: a player is expected to build ten to fifteen clubs before they can fund a
     * London or a Paris, and that long arc is the point of the prices rather than a flaw in them.
     *
     * So this asserts the much weaker and correct claim: a stadium is priced like a stadium.
     */
    public function testPricesArePlausibleAsRealBuildCosts(): void
    {
        foreach (['hamlet' => 1_000, 'small town' => 50_000, 'city' => 500_000, 'megacity' => 50_000_000] as $label => $population) {
            $price = $this->service->price($this->town('p' . $population, $population, 0));

            self::assertNotNull($price);
            self::assertGreaterThanOrEqual(
                StadiumEstimateService::PLAUSIBLE_MIN_PENCE,
                $price['pence'],
                sprintf('%s priced at £%s — too cheap to be a real ground', $label, number_format($price['pence'] / 100)),
            );
            self::assertLessThanOrEqual(
                StadiumEstimateService::PLAUSIBLE_MAX_PENCE,
                $price['pence'],
                sprintf('%s priced at £%s — beyond any stadium ever built', $label, number_format($price['pence'] / 100)),
            );
        }
    }

    /**
     * The per-seat rate stays inside the real construction range.
     *
     * This is the coefficient most likely to be "tidied" down by someone who thinks the prices
     * look too high. They are supposed to look high.
     */
    public function testPerSeatRateIsARealConstructionFigure(): void
    {
        $perSeatPounds = StadiumEstimateService::COST_PER_SEAT_PENCE / 100;

        self::assertGreaterThanOrEqual(
            1_000,
            $perSeatPounds,
            'below £1,000/seat this stops being a real build cost — see the constant docblock',
        );
        self::assertLessThanOrEqual(5_000, $perSeatPounds, 'above £5,000/seat exceeds real stadium builds');
    }

    /**
     * A flagship ground costs many clubs' worth of earnings, and that is intended.
     *
     * Asserted as a POSITIVE property rather than guarded against. If a future retune made the
     * biggest stadium cheap, this fails — which is the direction that would actually break the
     * design, since reaching London is meant to take ten to fifteen clubs.
     */
    public function testTheLargestGroundIsAMultiClubAmbition(): void
    {
        $startingBalance = self::getContainer()
            ->get(\App\Repository\StarterConfigRepository::class)
            ->getConfig()
            ->getStartingBalance();

        self::assertGreaterThan(0, $startingBalance);

        $london = $this->service->price($this->town('london', 8_982_000, 0));

        self::assertNotNull($london);
        self::assertSame(StadiumEstimateService::CAPACITY_MAX, $london['capacity']);
        self::assertTrue($london['capacityClamped']);

        self::assertGreaterThan(
            $startingBalance * 50,
            $london['pence'],
            'the flagship ground should be a long-horizon goal, not an early purchase',
        );
    }

    /** Bigger town, bigger price — the property a player will actually notice. */
    public function testPriceRisesWithPopulation(): void
    {
        $prices = [];

        foreach ([50_000, 250_000, 600_000] as $population) {
            $town = $this->town('t' . $population, $population, 0);
            $prices[] = $this->service->price($town)['pence'];
        }

        $sorted = $prices;
        sort($sorted);

        self::assertSame($sorted, $prices, 'price must increase with population');
        self::assertNotSame($prices[0], $prices[1], 'towns of different size should not be priced alike');
    }

    /** A dearer site costs more to build on, pound for pound. */
    public function testLandValueFeedsThroughToThePrice(): void
    {
        $cheap = $this->service->price($this->town('cheap', 200_000, 10_000_00));
        $dear  = $this->service->price($this->town('dear', 200_000, 50_000_00));

        self::assertSame(
            $dear['pence'] - $cheap['pence'],
            (50_000_00 - 10_000_00),
            'the land component should pass through at the documented multiplier',
        );
    }

    /**
     * Deterministic — the same inputs give the same pence, every time.
     *
     * Load-bearing, not hygiene: a hold freezes a quote for fifteen minutes and a player who
     * refreshes must see the same number. Any randomness here would break that promise.
     */
    public function testPricingIsDeterministic(): void
    {
        $town = $this->town('repeatable', 321_000, 44_444_00);

        $first = $this->service->price($town);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame($first, $this->service->price($town));
        }
    }

    /** Capacity is clamped at both ends so neither tail of the population range breaks it. */
    public function testCapacityIsClampedAtBothEnds(): void
    {
        $tiny = $this->service->price($this->town('tiny', 10, 0));
        $huge = $this->service->price($this->town('huge', 40_000_000, 0));

        self::assertSame(StadiumEstimateService::CAPACITY_MIN, $tiny['capacity']);
        self::assertSame(StadiumEstimateService::CAPACITY_MAX, $huge['capacity']);
        self::assertTrue($tiny['capacityClamped']);
        self::assertTrue($huge['capacityClamped']);
    }

    /** An unenriched location cannot be priced, and that is a state rather than an error. */
    public function testAnUnpriceableLocationReturnsNull(): void
    {
        self::assertNull($this->service->price($this->town('nopop', null, 5_000_00)));
        self::assertNull($this->service->price($this->town('noland', 100_000, null)));
        self::assertNull($this->service->refresh($this->town('neither', null, null)));
    }

    // ───────────────────────── persistence ─────────────────────────

    public function testRefreshStoresTheEstimateAndItsInputs(): void
    {
        $town     = $this->town('storable', 200_000, 25_000_00);
        $estimate = $this->service->refresh($town);

        self::assertNotNull($estimate);
        self::assertSame(StadiumEstimateService::MODEL_VERSION, $estimate->getModelVersion());
        self::assertTrue($estimate->isCurrent());

        $inputs = self::getContainer()
            ->get(StadiumEstimateInputRepository::class)
            ->findForEstimate($estimate);

        self::assertNotNull($inputs, 'an estimate with no recorded derivation is what the split row exists to prevent');

        $json = $inputs->getInputsJson();

        self::assertSame($estimate->getPence(), $json['pence']);
        self::assertSame(200_000, $json['population']);
        self::assertSame(25_000_00, $json['landPence']);
        // Coefficients are stored too, so the arithmetic is reproducible after a retune.
        self::assertArrayHasKey('coefficients', $json);
        self::assertSame(StadiumEstimateService::COST_PER_SEAT_PENCE, $json['coefficients']['costPerSeatPence']);
    }

    /**
     * Refreshing supersedes the old estimate, it does NOT delete it.
     *
     * plot_hold.stadium_estimate_id is ON DELETE RESTRICT, so a live hold's quote must still
     * exist after a refresh — that FK is how "the price will not move for fifteen minutes" is
     * actually enforced.
     */
    public function testRefreshSupersedesRatherThanDeletes(): void
    {
        $town  = $this->town('revalued', 200_000, 10_000_00);
        $first = $this->service->refresh($town);

        self::assertNotNull($first);
        $firstId = (string) $first->getId();

        // Revalue the land and rebuild.
        $town->setLandPence(90_000_00);
        $town->setLandValuedAt(new \DateTimeImmutable('+1 second'));
        $this->em->flush();

        $second = $this->service->refresh($town);

        self::assertNotNull($second);
        self::assertNotSame($firstId, (string) $second->getId());

        $this->em->clear();

        $old = $this->estimates->find($firstId);

        self::assertNotNull($old, 'the superseded estimate must still exist — a hold may point at it');
        self::assertFalse($old->isCurrent());
        self::assertNotNull($old->getSupersededAt());

        // And exactly one current estimate remains, which the partial unique also enforces.
        $current = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM stadium_estimate WHERE location_id = :id AND superseded_at IS NULL',
            ['id' => (string) $town->getId()],
        );

        self::assertSame(1, $current);
    }

    /** currentFor() reuses a fresh estimate rather than rebuilding on every read. */
    public function testCurrentForReusesAFreshEstimate(): void
    {
        $town  = $this->town('cached', 150_000, 8_000_00);
        $first = $this->service->currentFor($town);

        self::assertNotNull($first);
        self::assertSame((string) $first->getId(), (string) $this->service->currentFor($town)->getId());
    }

    /** ...but rebuilds once the land has been revalued underneath it. */
    public function testCurrentForRebuildsAfterALandRevaluation(): void
    {
        $town  = $this->town('moved', 150_000, 8_000_00);
        $first = $this->service->currentFor($town);

        self::assertNotNull($first);

        $town->setLandPence(60_000_00);
        $town->setLandValuedAt(new \DateTimeImmutable('+1 second'));
        $this->em->flush();

        $second = $this->service->currentFor($town);

        self::assertNotNull($second);
        self::assertNotSame((string) $first->getId(), (string) $second->getId());
        self::assertGreaterThan($first->getPence(), $second->getPence());
    }

    /** The partial unique really does permit many superseded rows and only one current. */
    public function testManySupersededEstimatesCoexist(): void
    {
        $town = $this->town('churned', 150_000, 8_000_00);

        for ($i = 1; $i <= 4; $i++) {
            $town->setLandPence(8_000_00 + ($i * 1_000_00));
            $town->setLandValuedAt(new \DateTimeImmutable("+{$i} seconds"));
            $this->em->flush();

            self::assertNotNull($this->service->refresh($town));
        }

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM stadium_estimate WHERE location_id = :id',
            ['id' => (string) $town->getId()],
        );
        $current = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM stadium_estimate WHERE location_id = :id AND superseded_at IS NULL',
            ['id' => (string) $town->getId()],
        );

        self::assertSame(4, $total);
        self::assertSame(1, $current);
    }
}
