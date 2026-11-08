<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Enum\Location\LandPlotStatus;
use App\Service\Location\LocationCapacityService;
use App\Service\Location\NpcClubPlotSeatingService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Seating NPC clubs on specific land plots.
 *
 * WHAT THIS CLOSES. LocationCapacityService always subtracted clubs from a town's available
 * plots — min(npcClubs, NPC_PLOT_CAP) — but as a COUNT, with nothing recording which plot. A
 * player could therefore buy the very plot the catalogue said a club occupied. These pin that
 * the link exists, that it respects the same cap the count did, and that the capacity figures
 * are unchanged in the normal case — because the point was to make an old rule true, not to
 * quietly change how much land a town sells.
 */
class NpcClubPlotSeatingTest extends KernelTestCase
{
    private const CODE = 'SE';

    private EntityManagerInterface $em;
    private NpcClubPlotSeatingService $seating;
    private LocationCapacityService $capacity;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em       = self::getContainer()->get(EntityManagerInterface::class);
        $this->seating  = self::getContainer()->get(NpcClubPlotSeatingService::class);
        $this->capacity = self::getContainer()->get(LocationCapacityService::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $c = self::getContainer()->get(Connection::class);
        $c->executeStatement("UPDATE npc_club SET land_plot_id = NULL WHERE country = :c", ['c' => self::CODE]);
        $c->executeStatement("DELETE FROM npc_club WHERE country = :c", ['c' => self::CODE]);
        $c->executeStatement(
            "DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)",
            ['c' => self::CODE],
        );
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::CODE]);
        $this->em->clear();
    }

    /** @return array{0: Location, 1: list<LandPlot>} */
    private function town(string $slug, int $plots): array
    {
        $profile = $this->em->getRepository(CountryProfile::class)->find(self::CODE)
            ?? new CountryProfile(self::CODE, 'Seatland');
        $this->em->persist($profile);

        $location = new Location($profile, ucfirst($slug), self::CODE . '-' . $slug, $slug);
        $location->setRegion('R');
        $location->setPopulation(100_000);
        $location->setCoordinates(50.0, 0.0);
        $location->setLandPence(5_000_000);
        $location->setLandValuedAt(new \DateTimeImmutable());
        $this->em->persist($location);

        $made = [];
        for ($i = 1; $i <= $plots; $i++) {
            $plot = new LandPlot($location, $i);
            $this->em->persist($plot);
            $made[] = $plot;
        }

        $this->em->flush();

        return [$location, $made];
    }

    private function club(Location $location, int $tier): NpcClub
    {
        $club = new NpcClub(
            name: sprintf('%s Tier %d FC', $location->getTown(), $tier),
            country: self::CODE,
            tier: $tier,
            reputation: 50,
            primaryColor: '#111111',
            secondaryColor: '#eeeeee',
            balance: 1_000_000,
            facilities: [],
            citySize: CitySize::MEDIUM,
            location: $location,
        );

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    public function testTheTopFlightClubTakesTheLowestNumberedPlot(): void
    {
        [$location] = $this->town('alpha', 4);
        $lower = $this->club($location, 6);
        $top   = $this->club($location, 1);

        $this->seating->seatCountry(self::CODE);
        $this->em->clear();

        $topAgain   = $this->em->find(NpcClub::class, $top->getId());
        $lowerAgain = $this->em->find(NpcClub::class, $lower->getId());

        self::assertSame(1, $topAgain->getLandPlot()?->getPlotNumber(), 'tier 1 takes plot 1');
        self::assertSame(2, $lowerAgain->getLandPlot()?->getPlotNumber(), 'tier 6 takes the next one');
    }

    /** The cap the capacity service always claimed, now actually enforced by the writes. */
    public function testATownSeatsNoMoreClubsThanTheCap(): void
    {
        [$location] = $this->town('beta', 6);

        for ($tier = 1; $tier <= 5; $tier++) {
            $this->club($location, $tier);
        }

        $this->seating->seatCountry(self::CODE);

        $seated = (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM npc_club WHERE country = :c AND land_plot_id IS NOT NULL',
            ['c' => self::CODE],
        );

        self::assertSame(LocationCapacityService::NPC_PLOT_CAP, $seated, 'five clubs, two plots taken');
    }

    /** A town with no plots has no ground to offer, so its clubs occupy nothing. */
    public function testAnUnpricedTownSeatsNobody(): void
    {
        [$location] = $this->town('gamma', 0);
        $this->club($location, 1);

        $result = $this->seating->seatCountry(self::CODE);

        self::assertSame(0, $result['seated']);
    }

    /** Running it twice must not move anybody or seat a second plot. */
    public function testSeatingIsIdempotent(): void
    {
        [$location] = $this->town('delta', 4);
        $club = $this->club($location, 1);

        $first = $this->seating->seatCountry(self::CODE);
        $this->em->clear();
        $plotAfterFirst = $this->em->find(NpcClub::class, $club->getId())->getLandPlot()?->getPlotNumber();

        $second = $this->seating->seatCountry(self::CODE);
        $this->em->clear();
        $plotAfterSecond = $this->em->find(NpcClub::class, $club->getId())->getLandPlot()?->getPlotNumber();

        self::assertSame(1, $first['seated']);
        self::assertSame(0, $second['seated'], 'a second run seats nothing new');
        self::assertSame($plotAfterFirst, $plotAfterSecond, 'and does not move anybody');
    }

    /**
     * THE FIGURES MUST NOT MOVE. shape() used to take min(npcClubs, cap); it now counts
     * distinct seated plots. In the ordinary case — enough plots, clubs seated — those are the
     * same number, and a client reading `available` off the catalogue must not see the land
     * supply change because of an internal correction.
     */
    public function testCapacityReportsTheSameAvailabilityAsTheCountDid(): void
    {
        [$location] = $this->town('epsilon', 5);
        $this->club($location, 1);
        $this->club($location, 2);

        $this->seating->seatCountry(self::CODE);

        $capacity = $this->capacity->forLocation((string) $location->getId());

        self::assertSame(5, $capacity['plots']);
        self::assertSame(2, $capacity['npcClubs']);
        self::assertSame(2, $capacity['npcOccupied'], 'two seated clubs occupy two plots');
        self::assertSame(3, $capacity['available'], 'and three remain for players');
    }

    /** A retired plot keeps its row, so the FK never fires — the seat has to be released. */
    public function testARetiredPlotReleasesItsSeat(): void
    {
        [$location, $plots] = $this->town('zeta', 2);
        $club = $this->club($location, 1);

        $this->seating->seatCountry(self::CODE);
        $this->em->clear();

        $plot = $this->em->find(LandPlot::class, $plots[0]->getId());
        $plot->setStatus(LandPlotStatus::RETIRED);
        $this->em->flush();

        $released = $this->seating->releaseRetiredSeats(self::CODE);
        $this->em->clear();

        self::assertSame(1, $released);
        self::assertNull(
            $this->em->find(NpcClub::class, $club->getId())->getLandPlot(),
            'a club must not keep pointing at ground that left the town',
        );
    }
}
