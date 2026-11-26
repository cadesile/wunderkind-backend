<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\League;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\NpcClub;
use App\Entity\User;
use App\Enum\CitySize;
use App\Enum\Location\CountryProfileStatus;
use App\Service\Location\CountryDeletionService;
use App\Service\Location\LocationDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The guarded country delete, and the per-town delete beside it. The rule (Courtney,
 * 2026-11-26): towns, enriched or not, never block; only plots a player has taken do.
 */
class CountryDeletionServiceTest extends KernelTestCase
{
    private const CODE = 'ZD';

    private EntityManagerInterface $em;
    private Connection $connection;
    private CountryDeletionService $countries;
    private LocationDeletionService $towns;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->countries  = self::getContainer()->get(CountryDeletionService::class);
        $this->towns      = self::getContainer()->get(LocationDeletionService::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    public function testAFreshlyGeneratedCountryIsDeletedWithEverythingBuiltForIt(): void
    {
        [, $plot, $location] = $this->world();

        $inventory = $this->countries->inventory(self::CODE);
        self::assertTrue($inventory['deletable']);
        self::assertSame([], $inventory['blockers']);

        $result = $this->countries->delete(self::CODE);

        self::assertTrue($result['deleted'], implode('; ', $result['reasons']));
        foreach ([
            'SELECT COUNT(*) FROM country_profile WHERE code = :c',
            'SELECT COUNT(*) FROM location WHERE country_code = :c',
            'SELECT COUNT(*) FROM league WHERE country = :c',
            'SELECT COUNT(*) FROM npc_club WHERE country = :c',
        ] as $sql) {
            self::assertSame(0, (int) $this->connection->fetchOne($sql, ['c' => self::CODE]), $sql);
        }
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM land_plot WHERE id = :id',
            ['id' => (string) $plot->getId()],
        ));
    }

    public function testATakenPlotBlocksTheCountryAndNothingIsDeleted(): void
    {
        [, $plot] = $this->world();
        $this->claim($plot);

        $result = $this->countries->delete(self::CODE);

        self::assertFalse($result['deleted']);
        self::assertStringContainsString('taken by a player', implode('; ', $result['reasons']));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM country_profile WHERE code = :c',
            ['c' => self::CODE],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM league WHERE country = :c',
            ['c' => self::CODE],
        ));
    }

    public function testALiveCountryIsRefused(): void
    {
        [$profile] = $this->world();
        $profile->setStatus(CountryProfileStatus::LIVE);
        $this->em->flush();

        $result = $this->countries->delete(self::CODE);

        self::assertFalse($result['deleted']);
        self::assertStringContainsString('Live', implode('; ', $result['reasons']));
    }

    public function testAnEnrichedTownWithFreePlotsAndASeatedNpcClubCanBeDeleted(): void
    {
        [, , $location] = $this->world();
        $id = (string) $location->getId();
        $this->em->clear();

        self::assertNull($this->towns->deleteTown($id));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM location WHERE id = :id', ['id' => $id]));
        // The NPC club is unseated, not deleted.
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM npc_club WHERE country = :c AND location_id IS NULL AND land_plot_id IS NULL',
            ['c' => self::CODE],
        ));
    }

    public function testATownWithATakenPlotIsKept(): void
    {
        [, $plot, $location] = $this->world();
        $this->claim($plot);

        $reason = $this->towns->deleteTown((string) $location->getId());

        self::assertNotNull($reason);
        self::assertStringContainsString('taken by a player', $reason);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @return array{CountryProfile, LandPlot, Location} */
    private function world(): array
    {
        $profile = new CountryProfile(self::CODE, 'Test ' . self::CODE);
        $profile->setStatus(CountryProfileStatus::DRAFT);
        $this->em->persist($profile);

        $location = new Location($profile, 'Deleteville', 'zd-deleteville', 'deleteville');
        $location->setRegion('R');
        $location->setPopulation(100_000);
        $location->setCitySize(CitySize::MEDIUM);
        $location->setCoordinates(52.0, 5.0);
        $location->setLandPence(1_000_000);
        $location->setLandValuedAt(new \DateTimeImmutable());
        $this->em->persist($location);

        $plot = new LandPlot($location, 1);
        $this->em->persist($plot);
        $this->em->persist(new LandPlot($location, 2));

        $league = new League(self::CODE, 1, 'ZD League');
        $this->em->persist($league);

        $npc = new NpcClub(
            'Deleteville FC', self::CODE, 1, 50, '#111111', '#eeeeee', 1_000_000, [],
            region: 'R', citySize: CitySize::MEDIUM, populationSize: 100_000, isCapital: false,
        );
        $npc->setLeague($league)->setLocation($location)->setLandPlot($plot);
        $this->em->persist($npc);

        $this->em->flush();

        return [$profile, $plot, $location];
    }

    private function claim(LandPlot $plot): void
    {
        $user = new User('country-delete-' . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->persist(new PlotClaim($plot, $user, 1_000_000));
        $this->em->flush();
    }

    private function purge(): void
    {
        $c   = $this->connection;
        $p   = ['c' => self::CODE];
        $loc = 'SELECT id FROM location WHERE country_code = :c';

        $c->executeStatement("DELETE FROM plot_claim WHERE location_id IN ({$loc})", $p);
        $c->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'country-delete-%@test.local'");
        $c->executeStatement('DELETE FROM npc_club WHERE country = :c', $p);
        $c->executeStatement('DELETE FROM league WHERE country = :c', $p);
        $c->executeStatement("DELETE FROM plot_hold WHERE plot_id IN (SELECT id FROM land_plot WHERE location_id IN ({$loc}))", $p);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN ({$loc})", $p);
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', $p);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', $p);
    }
}
