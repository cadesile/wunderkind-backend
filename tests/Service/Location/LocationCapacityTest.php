<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\NpcClub;
use App\Entity\User;
use App\Enum\CitySize;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\UserLedgerEntryType;
use App\Exception\Location\PlotUnavailableException;
use App\Service\Location\LandPlotProvisioner;
use App\Service\Location\LocationCapacityService;
use App\Service\Location\PlotClaimService;
use App\Service\Location\PlotHoldService;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * How many clubs a town can hold, and who gets the remaining plots.
 *
 * The rule: plots scale with city size, NPC clubs consume up to NPC_PLOT_CAP of them, and
 * players buy what is left. A town with nothing left is sold out — for everyone, by the same
 * arithmetic, with no separate "reserved" flag.
 */
class LocationCapacityTest extends KernelTestCase
{
    private const COUNTRY = 'ZK';

    private EntityManagerInterface $em;
    private Connection $connection;
    private LocationCapacityService $capacity;
    private LandPlotProvisioner $provisioner;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em          = $c->get(EntityManagerInterface::class);
        $this->connection  = $c->get(Connection::class);
        $this->capacity    = $c->get(LocationCapacityService::class);
        $this->provisioner = $c->get(LandPlotProvisioner::class);

        $this->purge();

        $country = new CountryProfile(self::COUNTRY, 'Capacityland');
        $country->setStatus(CountryProfileStatus::LIVE);
        $this->em->persist($country);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    // ───────────────────────── plot provisioning ─────────────────────────

    public function testPlotCountFollowsCitySize(): void
    {
        $big    = $this->town('Bigton', CitySize::BIG);
        $medium = $this->town('Medville', CitySize::MEDIUM);
        $small  = $this->town('Smallham', CitySize::SMALL);

        $this->provisioner->provisionCountry(self::COUNTRY);

        // Derived from city size, which derives from population, which enrichment supplies —
        // the chain that makes "enrichment decides how much football a town holds" true.
        self::assertSame(LocationCapacityService::plotsFor(CitySize::BIG), $this->plotCount($big));
        self::assertSame(LocationCapacityService::plotsFor(CitySize::MEDIUM), $this->plotCount($medium));
        self::assertSame(LocationCapacityService::plotsFor(CitySize::SMALL), $this->plotCount($small));
    }

    public function testProvisioningIsIdempotent(): void
    {
        $town = $this->town('Bigton', CitySize::BIG);

        $this->provisioner->provisionCountry(self::COUNTRY);
        $after = $this->plotCount($town);

        $second = $this->provisioner->provisionCountry(self::COUNTRY);

        self::assertSame(0, $second['plotsAdded'], 'a second pass adds nothing');
        self::assertSame($after, $this->plotCount($town));
    }

    public function testATownThatShrinksKeepsThePlotsItAlreadyHas(): void
    {
        $town = $this->town('Bigton', CitySize::BIG);
        $this->provisioner->provisionCountry(self::COUNTRY);

        $before = $this->plotCount($town);

        // Re-banded downward, as an enriched population legitimately can.
        $town = $this->reload($town);
        $town->setCitySize(CitySize::SMALL);
        $this->em->flush();

        $this->provisioner->provisionCountry(self::COUNTRY);

        // A plot can carry a claim and real money, and land_plot is RESTRICT-referenced by
        // plot_claim — so removing one because a population estimate moved is not a trade worth
        // making. It stops gaining, it does not lose.
        self::assertSame($before, $this->plotCount($town));
    }

    public function testPlotNumbersDoNotCollideAfterARetirement(): void
    {
        $town = $this->town('Bigton', CitySize::BIG);
        $this->provisioner->provisionCountry(self::COUNTRY);

        // Drop the middle plot, then top up again: numbering continues from the highest, so
        // uq_land_plot_location_number cannot collide.
        $this->connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id = :id AND plot_number = 2',
            ['id' => (string) $town->getId()],
        );
        $this->em->clear();

        $result = $this->provisioner->provisionCountry(self::COUNTRY);

        self::assertSame(1, $result['plotsAdded']);
        self::assertSame(LocationCapacityService::plotsFor(CitySize::BIG), $this->plotCount($town));
    }

    // ───────────────────────── the capacity arithmetic ─────────────────────────

    public function testAnEmptyTownHasEveryPlotAvailable(): void
    {
        $town = $this->town('Bigton', CitySize::BIG);
        $this->provisioner->provisionCountry(self::COUNTRY);

        $capacity = $this->capacity->forLocation((string) $town->getId());

        self::assertSame(LocationCapacityService::plotsFor(CitySize::BIG), $capacity['available']);
        self::assertFalse($capacity['soldOut']);
    }

    public function testNpcClubsAreCappedHoweverManyThereAre(): void
    {
        $town = $this->town('Bigton', CitySize::BIG);
        $this->provisioner->provisionCountry(self::COUNTRY);

        // Ten clubs in one town is entirely normal: generation puts ~144 clubs into a 40-town
        // list. Without the cap, a town could never be worth more plots than it had clubs.
        for ($i = 0; $i < 10; $i++) {
            $this->npcClub($town, 'Bigton Club ' . $i);
        }

        $capacity = $this->capacity->forLocation((string) $town->getId());

        self::assertSame(10, $capacity['npcClubs']);
        self::assertSame(LocationCapacityService::NPC_PLOT_CAP, $capacity['npcOccupied']);
        self::assertSame(
            LocationCapacityService::plotsFor(CitySize::BIG) - LocationCapacityService::NPC_PLOT_CAP,
            $capacity['available'],
        );
    }

    public function testAOnePlotTownWithAClubIsSoldOut(): void
    {
        $town = $this->town('Smallham', CitySize::SMALL);
        $this->provisioner->provisionCountry(self::COUNTRY);
        $this->npcClub($town, 'Smallham Rovers');

        $capacity = $this->capacity->forLocation((string) $town->getId());

        self::assertTrue($capacity['soldOut']);
        self::assertSame(0, $capacity['available']);
    }

    public function testAnOverSubscribedTownReportsZeroNotANegative(): void
    {
        $town = $this->town('Smallham', CitySize::SMALL);
        $this->provisioner->provisionCountry(self::COUNTRY);

        for ($i = 0; $i < 5; $i++) {
            $this->npcClub($town, 'Smallham Club ' . $i);
        }

        // One plot, two taken by clubs. Callers should never have to reason about a negative.
        self::assertSame(0, $this->capacity->forLocation((string) $town->getId())['available']);
    }

    // ───────────────────────── what a player can buy ─────────────────────────

    public function testAPlayerCanStillBuyInATownWithClubsIfCapacityRemains(): void
    {
        $town = $this->town('Bigton', CitySize::BIG);
        $this->provisioner->provisionCountry(self::COUNTRY);
        $this->npcClub($town, 'Bigton United');

        $hold = self::getContainer()->get(PlotHoldService::class)
            ->acquire($this->user(creditPence: 50_000_000_00), $this->firstPlot($town));

        // The point of the cap: an established club does not close a big town.
        self::assertNotNull($hold);
    }

    public function testASoldOutTownRefusesAHold(): void
    {
        $town = $this->town('Smallham', CitySize::SMALL);
        $this->provisioner->provisionCountry(self::COUNTRY);
        $this->npcClub($town, 'Smallham Rovers');

        $this->expectException(PlotUnavailableException::class);

        try {
            self::getContainer()->get(PlotHoldService::class)
                ->acquire($this->user(creditPence: 50_000_000_00), $this->firstPlot($town));
        } catch (PlotUnavailableException $e) {
            self::assertSame(PlotUnavailableException::TOWN_SOLD_OUT, $e->reason);
            // The arithmetic travels with the refusal, because "sold out" invites the question
            // "according to what?".
            self::assertSame(1, $e->context['plots']);
            self::assertSame(1, $e->context['npcOccupied']);

            throw $e;
        }
    }

    public function testAnAlreadyClaimedPlotSaysSoRatherThanSoldOut(): void
    {
        $town = $this->town('Bigton', CitySize::BIG);
        $this->provisioner->provisionCountry(self::COUNTRY);

        $plot = $this->firstPlot($town);
        $holds = self::getContainer()->get(PlotHoldService::class);

        $owner = $this->user(creditPence: 50_000_000_00);
        $hold  = $holds->acquire($owner, $plot);
        self::getContainer()->get(PlotClaimService::class)->claimFromHold($owner, (string) $hold->getId());

        try {
            $holds->acquire($this->user(creditPence: 50_000_000_00), $plot);
            self::fail('expected PlotUnavailableException');
        } catch (PlotUnavailableException $e) {
            // THE ORDER MATTERS. This plot is taken AND (in a one-plot town) the town would be
            // full; naming the plot is the more useful answer, so the specific check runs first.
            self::assertSame(PlotUnavailableException::PLOT_CLAIMED, $e->reason);
        }
    }

    // ───────────────────────── helpers ─────────────────────────

    private function plotCount(Location $town): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM land_plot WHERE location_id = :id AND status = 'active'",
            ['id' => (string) $town->getId()],
        );
    }

    private function firstPlot(Location $town): LandPlot
    {
        $this->em->clear();

        return $this->em->getRepository(LandPlot::class)
            ->findBy(['location' => $town->getId()], ['plotNumber' => 'ASC'])[0];
    }

    private function town(string $name, CitySize $size): Location
    {
        $country  = $this->em->find(CountryProfile::class, self::COUNTRY);
        $location = new Location($country, $name, strtolower(self::COUNTRY . '-' . $name), strtolower($name));
        $location->setRegion('R');
        $location->setCitySize($size);
        $location->setPopulation(100_000);
        $location->setCoordinates(53.0, -2.0);
        $location->setLandPence(1_000_00);
        $location->setLandValuedAt(new \DateTimeImmutable());

        $this->em->persist($location);
        $this->em->flush();

        return $location;
    }

    /**
     * Re-fetch a town after a country-wide provisioning pass.
     *
     * provisionCountry() clears the EntityManager when it writes — correct for a command walking
     * 865 towns, but it detaches whatever the caller was holding. Single-town provisioning does
     * not clear, so only the country pass needs this.
     */
    private function reload(Location $town): Location
    {
        return $this->em->find(Location::class, $town->getId()) ?? $town;
    }

    private function npcClub(Location $town, string $name): NpcClub
    {
        $town = $this->reload($town);

        $club = new NpcClub(
            $name, self::COUNTRY, 1, 50, '#111111', '#eeeeee', 1_000_000, [],
            region: 'R', citySize: $town->getCitySize(), populationSize: 100_000, isCapital: false,
            location: $town,
        );

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function user(int $creditPence = 0): User
    {
        $user = new User('cap-' . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        if ($creditPence > 0) {
            self::getContainer()->get(UserLedgerService::class)->append(
                $user, null, UserLedgerEntryType::STARTING_GRANT, $creditPence,
                'test', 'cap-grant-' . bin2hex(random_bytes(5)),
            );
        }

        return $user;
    }

    private function purge(): void
    {
        $c  = $this->connection;
        $cc = self::COUNTRY;
        $u  = "email LIKE 'cap-%@test.local'";

        $c->executeStatement("DELETE FROM plot_claim_valuation WHERE claim_id IN (SELECT pc.id FROM plot_claim pc JOIN location l ON l.id = pc.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_claim WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_hold WHERE plot_id IN (SELECT p.id FROM land_plot p JOIN location l ON l.id = p.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM stadium_estimate_input WHERE estimate_id IN (SELECT e.id FROM stadium_estimate e JOIN location l ON l.id = e.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM stadium_estimate WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM npc_club WHERE country = :x", ['x' => $cc]);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM \"user\" WHERE $u)");
        $c->executeStatement("DELETE FROM \"user\" WHERE $u");
        $c->executeStatement('DELETE FROM location WHERE country_code = :x', ['x' => $cc]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :x', ['x' => $cc]);
    }
}
