<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\UserLedgerEntryType;
use App\Service\Location\PlotClaimService;
use App\Service\Location\PlotHoldService;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/club/initialize with a locationId — the HTTP mapping of the founding refusals.
 *
 * The service-level behaviour is covered by ClubFoundingOnPlotTest; this is specifically about
 * the status codes, because the two refusals are deliberately DIFFERENT codes and a client
 * branches on them: 403 means "that is not your land" and is permanent for this caller, 409
 * means "you already built there" and is the caller's own doing.
 */
class ClubInitializeOnPlotTest extends WebTestCase
{
    private const COUNTRY = 'ZC';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);

        $this->purge();

        $country = new CountryProfile(self::COUNTRY, 'Clubland');
        $country->setStatus(CountryProfileStatus::LIVE);
        $this->em->persist($country);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    public function testFoundingOnAnOwnedPlotReturns201AndEchoesTheLocation(): void
    {
        [$userId, $locationId] = $this->ownedLocation();

        $response = $this->post($userId, ['clubName' => 'Owned FC', 'locationId' => $locationId]);

        $this->assertSame(201, $response['status']);
        $this->assertSame($locationId, $response['body']['homeLocationId']);
    }

    public function testFoundingWithoutALocationStillReturns201(): void
    {
        $userId = (string) $this->user()->getId();

        $response = $this->post($userId, ['clubName' => 'Groundless FC']);

        // The pre-existing contract, unchanged — and homeLocationId is present and null rather
        // than absent, so a client need not branch on key existence.
        $this->assertSame(201, $response['status']);
        $this->assertNull($response['body']['homeLocationId']);
    }

    public function testFoundingOnSomeoneElsesLandIs403(): void
    {
        $location = $this->location('Theirs');
        $userId   = (string) $this->user()->getId();

        $response = $this->post($userId, [
            'clubName'   => 'Trespass FC',
            'locationId' => (string) $location->getId(),
        ]);

        $this->assertSame(403, $response['status'], 'not your land is an authorisation answer');
        $this->assertSame('location_not_owned', $response['body']['error']);
    }

    public function testFoundingTwiceOnTheSameClaimIs409(): void
    {
        [$userId, $locationId] = $this->ownedLocation();

        $this->post($userId, ['clubName' => 'First FC', 'locationId' => $locationId]);
        $response = $this->post($userId, ['clubName' => 'Second FC', 'locationId' => $locationId]);

        $this->assertSame(409, $response['status'], 'already built there is a conflict, not a 403');
        $this->assertSame('location_club_exists', $response['body']['error']);
        $this->assertSame('First FC', $response['body']['clubName']);
    }

    public function testADuplicateNameAtOneLocationIsTheExisting422(): void
    {
        $location = $this->location('Onetown');

        [$firstUserId]  = $this->ownedLocation($location, plotNumber: 1);
        [$secondUserId] = $this->ownedLocation($location, plotNumber: 2);

        $this->post($firstUserId, ['clubName' => 'Onetown FC', 'locationId' => (string) $location->getId()]);
        $response = $this->post($secondUserId, [
            'clubName'   => 'Onetown FC',
            'locationId' => (string) $location->getId(),
        ]);

        // No new error code: the client already handles club_name_taken.
        $this->assertSame(422, $response['status']);
        $this->assertSame('club_name_taken', $response['body']['error']);
    }

    public function testAMalformedLocationIdIsRejectedByValidation(): void
    {
        $userId = (string) $this->user()->getId();

        $response = $this->post($userId, ['clubName' => 'Bad FC', 'locationId' => 'not-a-uuid']);

        // Assert\Uuid on the DTO, so this never reaches the service or the database.
        $this->assertSame(422, $response['status']);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * One authenticated POST, with a fresh client — loginUser() authenticates exactly one
     * request against this stateless JWT firewall (see AdminMessageControllerTest).
     *
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(string $userId, array $payload): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $userId);
        $client->loginUser($user, 'api');

        $client->request(
            'POST',
            '/api/club/initialize',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );

        // Rebooting swapped the manager, so re-point the fields the helpers below use.
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body'   => json_decode($client->getResponse()->getContent(), true) ?? [],
        ];
    }

    /** @return array{0: string, 1: string} user id, location id */
    private function ownedLocation(?Location $location = null, int $plotNumber = 1): array
    {
        $location ??= $this->location('Ownedtown');

        $plot = $this->em->getRepository(LandPlot::class)
            ->findOneBy(['location' => $location, 'plotNumber' => $plotNumber]);

        if ($plot === null) {
            $plot = new LandPlot($location, $plotNumber);
            $this->em->persist($plot);
            $this->em->flush();
        }

        $user  = $this->user(creditPence: 50_000_000_00);
        $hold  = self::getContainer()->get(PlotHoldService::class)->acquire($user, $plot);
        self::getContainer()->get(PlotClaimService::class)->claimFromHold($user, (string) $hold->getId());

        return [(string) $user->getId(), (string) $location->getId()];
    }

    private function location(string $town): Location
    {
        $existing = $this->em->getRepository(Location::class)
            ->findOneBy(['country' => self::COUNTRY, 'town' => $town]);

        if ($existing !== null) {
            return $existing;
        }

        $profile  = $this->em->find(CountryProfile::class, self::COUNTRY);
        $location = new Location($profile, $town, strtolower(self::COUNTRY . '-' . $town), strtolower($town));
        $location->setRegion('R');
        $location->setPopulation(150_000);
        $location->setCoordinates(53.0, -2.0);
        $location->setLandPence(1_000_00);
        $location->setLandValuedAt(new \DateTimeImmutable());

        $this->em->persist($location);
        $this->em->persist(new LandPlot($location, 1));
        $this->em->flush();

        return $location;
    }

    private function user(int $creditPence = 0): User
    {
        $user = new User('cinit-' . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->em->flush();

        if ($creditPence > 0) {
            self::getContainer()->get(UserLedgerService::class)->append(
                $user,
                null,
                UserLedgerEntryType::STARTING_GRANT,
                $creditPence,
                'test',
                'cinit-grant-' . bin2hex(random_bytes(5)),
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
        $c->executeStatement("DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'cinit-%@test.local')");
        $c->executeStatement("DELETE FROM club WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'cinit-%@test.local')");
        $c->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'cinit-%@test.local'");
        $c->executeStatement('DELETE FROM location WHERE country_code = :x', ['x' => $cc]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :x', ['x' => $cc]);
    }
}
