<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Entity\Location\LocationRival;
use App\Repository\Location\LocationRepository;
use App\Repository\Location\LocationRivalRepository;
use App\Service\Location\Haversine;
use App\Service\Location\LocationRivalService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Nearest-four rival ranking, against the real database and the real haversine SQL.
 *
 * The geometry is built from pure latitude offsets so every expected distance is arithmetic
 * rather than trigonometry: one kilometre of latitude is 1/111.32 of a degree everywhere, so a
 * town placed N km north of another is N km away, and the test's intent stays readable.
 */
class LocationRivalServiceTest extends KernelTestCase
{
    private const COUNTRY = 'ZR';
    private const OTHER   = 'ZQ';

    /** Degrees of latitude per kilometre. */
    private const DEG_PER_KM = 1 / 111.32;

    private const BASE_LAT = 53.0;
    private const BASE_LNG = -2.0;

    private EntityManagerInterface $em;
    private Connection $connection;
    private LocationRivalService $service;
    private LocationRivalRepository $rivals;
    private LocationRepository $locations;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->service    = self::getContainer()->get(LocationRivalService::class);
        $this->rivals     = self::getContainer()->get(LocationRivalRepository::class);
        $this->locations  = self::getContainer()->get(LocationRepository::class);

        $this->purge();

        foreach ([self::COUNTRY, self::OTHER] as $code) {
            $this->em->persist(new CountryProfile($code, "Rivalland {$code}"));
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        foreach ([self::COUNTRY, self::OTHER] as $code) {
            $this->connection->executeStatement(
                'DELETE FROM location_rival WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)
                    OR rival_location_id IN (SELECT id FROM location WHERE country_code = :c)',
                ['c' => $code],
            );
            $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => $code]);
            $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => $code]);
        }
    }

    /** A town $northKm north of the base point, so its distances are readable arithmetic. */
    private function town(string $name, ?float $northKm, string $code = self::COUNTRY): Location
    {
        $country  = $this->em->find(CountryProfile::class, $code);
        $location = new Location($country, $name, strtolower("{$code}-{$name}"), strtolower($name));
        $location->setRegion('R');

        if ($northKm !== null) {
            $location->setCoordinates(self::BASE_LAT + ($northKm * self::DEG_PER_KM), self::BASE_LNG);
        }

        $this->em->persist($location);
        $this->em->flush();

        return $location;
    }

    /** @return array<string, float> rival town => distance, in rank order */
    private function rivalsOf(Location $location): array
    {
        $this->em->clear();
        $fresh = $this->locations->find((string) $location->getId());

        $out = [];
        foreach ($this->rivals->findForLocation($fresh) as $rival) {
            $out[$rival->getRivalLocation()->getTown()] = $rival->getDistanceKm();
        }

        return $out;
    }

    public function testRanksNearestFirstWithAccurateDistances(): void
    {
        $hub = $this->town('hub', 0.0);
        $this->town('near', 5.0);
        $this->town('far', 20.0);

        self::assertSame(2, $this->service->recomputeFor($hub));

        $rivals = $this->rivalsOf($hub);

        self::assertSame(['near', 'far'], array_keys($rivals), 'nearest must rank first');
        self::assertEqualsWithDelta(5.0, $rivals['near'], 0.2);
        self::assertEqualsWithDelta(20.0, $rivals['far'], 0.2);
    }

    /** Nothing beyond 25 miles is a rival, however close it is to being one. */
    public function testIgnoresTownsBeyondTheSearchRadius(): void
    {
        $hub = $this->town('hub', 0.0);
        $this->town('inside', LocationRivalService::searchRadiusKm() - 1.0);
        $this->town('outside', LocationRivalService::searchRadiusKm() + 1.0);

        $this->service->recomputeFor($hub);

        self::assertSame(['inside'], array_keys($this->rivalsOf($hub)));
    }

    /**
     * Five candidates, four slots — and the excluded one still names the hub.
     *
     * This is the case that demonstrates rivalry is DIRECTIONAL rather than mutual. The hub's
     * four slots are taken by its four closest neighbours, so the distant town is not among its
     * rivals; but the hub is that town's own nearest neighbour, so it appears in ITS set. A
     * symmetric-pair model could not represent this, and would either drop the relationship or
     * push the hub over four rivals.
     */
    public function testRivalryIsDirectionalWhenTheCapBinds(): void
    {
        $hub = $this->town('hub', 0.0);

        foreach ([1.0, 2.0, 3.0, 4.0] as $km) {
            $this->town('close' . (int) $km, $km);
        }

        // 30 km south: inside the search radius, but fifth in line for the hub's four slots.
        $distant = $this->town('distant', -30.0);

        $this->service->recomputeFor($hub);
        $this->service->recomputeFor($distant);

        $hubRivals = $this->rivalsOf($hub);

        self::assertCount(LocationRival::MAX_RANK, $hubRivals, 'the hub must fill exactly four slots');
        self::assertSame(['close1', 'close2', 'close3', 'close4'], array_keys($hubRivals));
        self::assertArrayNotHasKey('distant', $hubRivals);

        // ...and the asymmetry: the distant town does list the hub.
        self::assertArrayHasKey(
            'hub',
            $this->rivalsOf($distant),
            'the excluded town should still name the hub — rivalry is directional',
        );
    }

    /** An isolated town gets zero rivals, and that is an answer, not a failure. */
    public function testIsolatedTownGetsNoRivalsButIsStamped(): void
    {
        $lonely = $this->town('lonely', 0.0);
        $this->town('miles-away', 500.0);

        self::assertSame(0, $this->service->recomputeFor($lonely));
        self::assertSame([], $this->rivalsOf($lonely));

        $this->em->clear();
        $fresh = $this->locations->find((string) $lonely->getId());

        self::assertNotNull(
            $fresh->getRivalsComputedAt(),
            'an isolated town must still be stamped, or every pass revisits it for ever',
        );
    }

    /** A town with no coordinates is skipped — before enrichment, that is every town. */
    public function testSkipsTownsWithoutCoordinates(): void
    {
        $noCoords = $this->town('nowhere', null);
        $this->town('somewhere', 5.0);

        self::assertSame(0, $this->service->recomputeFor($noCoords));
        self::assertSame([], $this->rivalsOf($noCoords));
    }

    /** Recomputing replaces the previous set wholesale rather than appending to it. */
    public function testRecomputeReplacesTheExistingSet(): void
    {
        $hub = $this->town('hub', 0.0);
        $old = $this->town('old', 5.0);

        $this->service->recomputeFor($hub);
        self::assertSame(['old'], array_keys($this->rivalsOf($hub)));

        // Move the old rival out of range and add a new one inside it.
        $this->connection->executeStatement(
            'UPDATE location SET lat = :lat WHERE id = :id',
            ['lat' => self::BASE_LAT + (500 * self::DEG_PER_KM), 'id' => (string) $old->getId()],
        );
        $this->town('new', 3.0);

        $this->em->clear();
        $hub = $this->locations->find((string) $hub->getId());
        $this->service->recomputeFor($hub);

        self::assertSame(['new'], array_keys($this->rivalsOf($hub)), 'the stale rival should be gone, not kept');
    }

    /** Borders are respected by default — a league pyramid is national. */
    public function testDoesNotCrossBordersByDefault(): void
    {
        $home    = $this->town('home', 0.0);
        $abroad  = $this->town('abroad', 5.0, self::OTHER);

        $this->service->recomputeFor($home);
        self::assertSame([], $this->rivalsOf($home), 'a foreign town must not be a rival by default');

        $this->em->clear();
        $home = $this->locations->find((string) $home->getId());

        $this->service->recomputeFor($home, crossBorder: true);
        self::assertSame(['abroad'], array_keys($this->rivalsOf($home)), '--cross-border should include it');

        self::assertNotNull($abroad->getId());
    }

    /** A retired town stops being offered as a rival. */
    public function testRetiredTownsAreNotRivals(): void
    {
        $hub     = $this->town('hub', 0.0);
        $retired = $this->town('retired', 5.0);

        $this->connection->executeStatement(
            "UPDATE location SET status = 'retired' WHERE id = :id",
            ['id' => (string) $retired->getId()],
        );

        $this->service->recomputeFor($hub);

        self::assertSame([], $this->rivalsOf($hub));
    }

    /** --stale-only picks up exactly the rows enrichment marked, and stamps them. */
    public function testRecomputeStaleProcessesOnlyUnstampedRows(): void
    {
        $stale = $this->town('stale', 0.0);
        $this->town('neighbour', 5.0);

        $fresh = $this->town('fresh', 100.0);
        $this->town('freshneighbour', 105.0);
        $this->service->recomputeFor($fresh);

        $this->em->clear();

        $stats = $this->service->recomputeStale(self::COUNTRY);

        self::assertSame(3, $stats['processed'], 'stale + neighbour + freshneighbour, but not fresh');
        self::assertSame(0, $stats['skipped']);

        // A second pass has nothing left to do.
        self::assertSame(0, $this->service->recomputeStale(self::COUNTRY)['processed']);

        self::assertNotSame([], $this->rivalsOf($stale));
    }

    /** Towns without coordinates are counted as skipped, not silently dropped. */
    public function testRecomputeStaleReportsSkippedTowns(): void
    {
        $this->town('nowhere1', null);
        $this->town('nowhere2', null);
        $this->town('placed', 0.0);
        $this->town('placed-neighbour', 5.0);

        $stats = $this->service->recomputeStale(self::COUNTRY);

        self::assertSame(2, $stats['processed']);
        self::assertSame(2, $stats['skipped']);
    }

    /** The service's radius really is 25 miles, not a hardcoded kilometre figure. */
    public function testSearchRadiusIsTwentyFiveMiles(): void
    {
        self::assertEqualsWithDelta(
            Haversine::milesToKm(25.0),
            LocationRivalService::searchRadiusKm(),
            0.001,
        );
        self::assertEqualsWithDelta(40.23, LocationRivalService::searchRadiusKm(), 0.01);
    }
}
