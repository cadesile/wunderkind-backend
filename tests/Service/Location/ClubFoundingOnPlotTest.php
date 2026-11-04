<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Club;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\UserLedgerEntryType;
use App\Exception\ClubNameTakenException;
use App\Exception\Location\PlotUnavailableException;
use App\Repository\ClubRepository;
use App\Repository\Location\PlotClaimRepository;
use App\Service\ClubInitializationService;
use App\Service\Location\PlotClaimService;
use App\Service\Location\PlotHoldService;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Founding a club on a plot — the link that was half-built until now.
 *
 * PlotClaim has carried a nullable club since the land economy shipped, and
 * PlotClaimService::attachClub() existed with NO CALLER anywhere in src/. These tests exist to
 * keep both halves wired: the assertion that matters most is that founding sets BOTH
 * club.home_location_id and plot_claim.club_id, because one without the other leaves a plot
 * that reads as owned by nobody while uq_plot_claim_active_plot still refuses to sell it again.
 */
class ClubFoundingOnPlotTest extends KernelTestCase
{
    private const COUNTRY = 'ZF';

    private EntityManagerInterface $em;
    private Connection $connection;
    private ClubInitializationService $init;
    private PlotClaimRepository $claims;
    private ClubRepository $clubs;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);
        $this->init       = $c->get(ClubInitializationService::class);
        $this->claims     = $c->get(PlotClaimRepository::class);
        $this->clubs      = $c->get(ClubRepository::class);

        $this->purge();

        $country = new CountryProfile(self::COUNTRY, 'Foundland');
        $country->setStatus(CountryProfileStatus::LIVE);
        $this->em->persist($country);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    // ───────────────────────── founding without a plot ─────────────────────────

    public function testFoundingWithoutALocationIsUnchanged(): void
    {
        $user = $this->user();

        $club = $this->init->initializeClub($user, 'Groundless FC');

        // The entire pre-existing flow still works: a club with no ground is still a club, and
        // every client that does not know about locationId keeps functioning.
        $this->assertNull($club->getHomeLocation());
        $this->assertSame('Groundless FC', $club->getName());
        $this->assertGreaterThan(0, $club->getBalance(), 'the starter balance is still granted');
    }

    public function testNameNormalisedIsMaintainedByTheEntity(): void
    {
        $club = $this->init->initializeClub($this->user(), 'Bréscia  AS');

        // The unique index is built on this column, so it has to be right without anyone
        // remembering to set it.
        $this->assertSame('brescia as', $club->getNameNormalised());

        $club->setName('Düsseldorf FC');
        $this->assertSame('dusseldorf fc', $club->getNameNormalised(), 'setName keeps it in step');
    }

    // ───────────────────────── founding on a plot ─────────────────────────

    public function testFoundingOnAnOwnedPlotLinksBothSides(): void
    {
        [$user, $claim] = $this->userWithClaim('Plotsville');

        $club = $this->init->initializeClub(
            $user,
            'Plotsville FC',
            locationId: (string) $claim->getLocation()->getId(),
        );

        $this->assertNotNull($club->getHomeLocation());
        $this->assertSame('Plotsville', $club->getHomeLocation()->getTown());

        // THE OTHER HALF. Read back from the database, not from the in-memory claim, so this
        // fails if attachClub() was called but never flushed.
        $this->em->clear();
        $reloaded = $this->em->find(PlotClaim::class, $claim->getId());

        $this->assertNotNull($reloaded->getClub(), 'the claim must point back at the club');
        $this->assertSame((string) $club->getId(), (string) $reloaded->getClub()->getId());
        $this->assertSame('Plotsville FC', $reloaded->getClubNameAtClaim(), 'the name is frozen for display');
    }

    public function testFoundingOnAPlotInheritsTheCountry(): void
    {
        [$user, $claim] = $this->userWithClaim('Plotsville');

        $club = $this->init->initializeClub(
            $user,
            'Plotsville FC',
            locationId: (string) $claim->getLocation()->getId(),
        );

        // A location belongs to exactly one country, so the client need not send it twice.
        $this->assertSame(self::COUNTRY, $club->getCountry());
    }

    public function testAnExplicitCountryIsNotOverriddenByTheLocation(): void
    {
        [$user, $claim] = $this->userWithClaim('Plotsville');

        $club = $this->init->initializeClub(
            $user,
            'Plotsville FC',
            country: 'IT',
            locationId: (string) $claim->getLocation()->getId(),
        );

        // The caller said IT. Silently rewriting it would be the service second-guessing an
        // explicit, already-validated argument.
        $this->assertSame('IT', $club->getCountry());
    }

    // ───────────────────────── the refusals ─────────────────────────

    public function testFoundingOnLandYouDoNotOwnIsRefusedAndWritesNoClub(): void
    {
        $user     = $this->user();
        $location = $this->location('Someoneelsesville');

        $before = $this->countClubsFor($user);

        try {
            $this->init->initializeClub($user, 'Trespass FC', locationId: (string) $location->getId());
            $this->fail('expected PlotUnavailableException');
        } catch (PlotUnavailableException $e) {
            $this->assertSame(PlotUnavailableException::NOT_OWNED, $e->reason);
        }

        // NO ORPHAN. The location is resolved before the club is persisted precisely so a
        // refused founding leaves nothing behind.
        $this->assertSame($before, $this->countClubsFor($user), 'a refused founding must write no club');
    }

    public function testFoundingOnAnUnknownLocationIsTheSameRefusal(): void
    {
        $user = $this->user();

        $this->expectException(PlotUnavailableException::class);

        // Deliberately indistinguishable from "someone else owns it" — the alternative confirms
        // the existence of another player's holding to anyone who guesses an id.
        $this->init->initializeClub(
            $user,
            'Nowhere FC',
            locationId: '01932000-0000-7000-8000-00000000beef',
        );
    }

    public function testASecondClubCannotBeFoundedOnTheSameClaim(): void
    {
        [$user, $claim] = $this->userWithClaim('Plotsville');

        $this->init->initializeClub($user, 'First FC', locationId: (string) $claim->getLocation()->getId());

        try {
            $this->init->initializeClub($user, 'Second FC', locationId: (string) $claim->getLocation()->getId());
            $this->fail('expected PlotUnavailableException');
        } catch (PlotUnavailableException $e) {
            $this->assertSame(PlotUnavailableException::CLAIM_HAS_CLUB, $e->reason);
            $this->assertSame('First FC', $e->context['clubName']);
        }
    }

    // ───────────────────────── name uniqueness per location ─────────────────────────

    public function testTheSameNameIsAllowedAtTwoDifferentLocations(): void
    {
        [$userA, $claimA] = $this->userWithClaim('Northville');
        [$userB, $claimB] = $this->userWithClaim('Southville');

        $this->init->initializeClub($userA, 'United FC', locationId: (string) $claimA->getLocation()->getId());
        $second = $this->init->initializeClub($userB, 'United FC', locationId: (string) $claimB->getLocation()->getId());

        // PER LOCATION, not per country. Blocking this would refuse a legitimate club across
        // forty other towns.
        $this->assertSame('United FC', $second->getName());
    }

    public function testTheSameNameTwiceAtOneLocationIsRefused(): void
    {
        $location = $this->location('Onetown');

        [$userA, $claimA] = $this->userWithClaim('Onetown', location: $location, plotNumber: 1);
        [$userB, $claimB] = $this->userWithClaim('Onetown', location: $location, plotNumber: 2);

        $this->init->initializeClub($userA, 'Onetown FC', locationId: (string) $location->getId());

        // The existing exception and therefore the existing 422 — no new error code to learn.
        $this->expectException(ClubNameTakenException::class);
        $this->init->initializeClub($userB, 'Onetown FC', locationId: (string) $location->getId());
    }

    public function testUniquenessIgnoresCaseAndAccents(): void
    {
        $location = $this->location('Onetown');

        [$userA] = $this->userWithClaim('Onetown', location: $location, plotNumber: 1);
        [$userB] = $this->userWithClaim('Onetown', location: $location, plotNumber: 2);

        $this->init->initializeClub($userA, 'Bréscia AS', locationId: (string) $location->getId());

        $this->expectException(ClubNameTakenException::class);
        $this->init->initializeClub($userB, 'brescia  as', locationId: (string) $location->getId());
    }

    public function testClubsWithNoLocationNeverCollideWithEachOther(): void
    {
        // The reason uq_club_name_per_location is PARTIAL: every pre-existing club and every
        // spoof clone has a NULL home_location_id, and they must stay free to share names.
        $first  = $this->init->initializeClub($this->user(), 'Duplicate FC');
        $second = $this->init->initializeClub($this->user(), 'Duplicate FC');

        $this->assertNotSame((string) $first->getId(), (string) $second->getId());
    }

    public function testNameExistsAtLocationIsScopedToTheLocation(): void
    {
        [$user, $claim] = $this->userWithClaim('Scopeville');
        $other          = $this->location('Elsewhere');

        $this->init->initializeClub($user, 'Scope FC', locationId: (string) $claim->getLocation()->getId());

        $this->assertTrue($this->clubs->nameExistsAtLocation('scope fc', $claim->getLocation()));
        $this->assertFalse($this->clubs->nameExistsAtLocation('Scope FC', $other));
    }

    // ───────────────────────── helpers ─────────────────────────

    private function countClubsFor(User $user): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM club WHERE user_id = :u',
            ['u' => (string) $user->getId()],
        );
    }

    private function location(string $town, int $plotNumber = 1): Location
    {
        $existing = $this->em->getRepository(Location::class)->findOneBy([
            'country' => self::COUNTRY,
            'town'    => $town,
        ]);

        if ($existing !== null) {
            return $existing;
        }

        $profile  = $this->em->find(CountryProfile::class, self::COUNTRY);
        $location = new Location(
            $profile,
            $town,
            strtolower(self::COUNTRY . '-' . $town),
            strtolower($town),
        );
        $location->setRegion('R');
        $location->setPopulation(150_000);
        $location->setCoordinates(53.0, -2.0);
        $location->setLandPence(1_000_00);
        $location->setLandValuedAt(new \DateTimeImmutable());

        $this->em->persist($location);
        $this->em->persist(new LandPlot($location, $plotNumber));
        $this->em->flush();

        return $location;
    }

    /** @return array{0: User, 1: PlotClaim} */
    private function userWithClaim(string $town, ?Location $location = null, int $plotNumber = 1): array
    {
        $location ??= $this->location($town);

        if ($this->em->getRepository(LandPlot::class)->findOneBy(['location' => $location, 'plotNumber' => $plotNumber]) === null) {
            $this->em->persist(new LandPlot($location, $plotNumber));
            $this->em->flush();
        }

        $plot = $this->em->getRepository(LandPlot::class)
            ->findOneBy(['location' => $location, 'plotNumber' => $plotNumber]);

        $user = $this->user(creditPence: 50_000_000_00);

        $hold  = self::getContainer()->get(PlotHoldService::class)->acquire($user, $plot);
        $claim = self::getContainer()->get(PlotClaimService::class)
            ->claimFromHold($user, (string) $hold->getId());

        return [$user, $claim];
    }

    private function user(int $creditPence = 0): User
    {
        $user = new User('found-' . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        if ($creditPence > 0) {
            self::getContainer()->get(UserLedgerService::class)->append(
                $user,
                null,
                UserLedgerEntryType::STARTING_GRANT,
                $creditPence,
                'test',
                'found-grant-' . bin2hex(random_bytes(5)),
            );
        }

        return $user;
    }

    private function purge(): void
    {
        $c  = $this->connection;
        $cc = self::COUNTRY;

        $c->executeStatement("UPDATE club SET home_location_id = NULL WHERE home_location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_claim_valuation WHERE claim_id IN (SELECT pc.id FROM plot_claim pc JOIN location l ON l.id = pc.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_claim WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_hold WHERE plot_id IN (SELECT p.id FROM land_plot p JOIN location l ON l.id = p.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM stadium_estimate_input WHERE estimate_id IN (SELECT e.id FROM stadium_estimate e JOIN location l ON l.id = e.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM stadium_estimate WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'found-%@test.local')");
        $c->executeStatement("DELETE FROM club WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'found-%@test.local')");
        $c->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'found-%@test.local'");
        $c->executeStatement('DELETE FROM location WHERE country_code = :x', ['x' => $cc]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :x', ['x' => $cc]);
    }
}
