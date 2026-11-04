<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The new-game flow as a client that knows NOTHING about locations would drive it.
 *
 * BACKWARDS-COMPATIBILITY GUARD. The land economy added `locationId` to club creation, a
 * location link to NPC clubs, town capacity, and several additive response keys. None of that
 * may become required: a shipped app build predates all of it and must keep working untouched
 * until it is updated on its own schedule.
 *
 * So every request here sends the OLD payloads only — no locationId, no new query parameters —
 * and asserts both that the flow completes and that the keys the old client reads are still
 * present with the right types. A new key appearing is fine; a missing or renamed one is not.
 */
class LegacyNewGameFlowTest extends WebTestCase
{
    private ?string $userId = null;

    public function testStarterConfigStillCarriesEveryKeyTheOldClientReads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/starter-config');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        // The old client reads these by name. `startingBalance` in particular must keep BOTH its
        // name and its meaning (pence) — the account-level range added alongside it is a
        // separate, additive pair.
        foreach ([
            'startingBalance', 'starterPlayerCount', 'starterManagerCount', 'starterCoachCount',
            'starterScoutCount', 'starterSponsorTier', 'starterClubTier', 'enabledCountries',
            'defaultFacilities', 'fanBaseRanges', 'tutorial',
        ] as $key) {
            self::assertArrayHasKey($key, $data, "starter-config lost $key");
        }

        self::assertIsInt($data['startingBalance']);
    }

    public function testNameOptionsStillReturnsCitiesSuffixesAndTakenNames(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/club/name-options?country=EN');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        self::assertArrayHasKey('cities', $data);
        self::assertArrayHasKey('suffixes', $data);
        self::assertArrayHasKey('takenNames', $data);

        // The guard that already exists for this endpoint: an empty city list is a dead end at
        // club creation, and moving town names into the database made that reachable once.
        self::assertNotEmpty($data['cities'], 'an empty city list is a dead end at club creation');
    }

    public function testCountriesResponseIsOnlyAdditive(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/countries');

        self::assertResponseIsSuccessful();
        $rows = json_decode($client->getResponse()->getContent(), true);

        self::assertNotEmpty($rows);

        // isoAlpha2/isIsoCode were added; name/country/code must be untouched, because the
        // client builds its nationality<->code maps from exactly these.
        foreach (['name', 'country', 'code'] as $key) {
            self::assertArrayHasKey($key, $rows[0], "countries lost $key");
        }

        self::assertSame('EN', $this->rowFor($rows, 'EN')['code'], 'EN must not have become GB');
    }

    public function testAClubCanStillBeCreatedWithoutAnyLocationFields(): void
    {
        $this->seedUser();

        // EXACTLY the old payload: clubName and country, nothing else.
        $response = $this->post('/api/club/initialize', [
            'clubName' => 'Legacy Town FC ' . bin2hex(random_bytes(3)),
            'country'  => 'EN',
        ]);

        self::assertSame(201, $response['status'], 'the old club-creation payload must still work');

        foreach (['id', 'name', 'starterBundle'] as $key) {
            self::assertArrayHasKey($key, $response['body'], "club/initialize lost $key");
        }

        foreach (['startingBalance', 'players', 'coaches', 'scouts'] as $key) {
            self::assertArrayHasKey($key, $response['body']['starterBundle'], "starterBundle lost $key");
        }

        // The club exists, has the starter balance, and has NO home location — which is the
        // whole point: founding on a plot is opt-in.
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $club = $em->getRepository(Club::class)->find($response['body']['id']);

        self::assertNotNull($club);
        self::assertNull($club->getHomeLocation(), 'a club founded the old way has no plot');
        self::assertGreaterThan(0, $club->getBalance(), 'the starter balance is still granted');
    }

    public function testTheNewClubIsNotChargedForLand(): void
    {
        $this->seedUser();

        $response = $this->post('/api/club/initialize', [
            'clubName' => 'Unbilled FC ' . bin2hex(random_bytes(3)),
            'country'  => 'EN',
        ]);

        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $ledger = self::getContainer()->get(\App\Repository\UserLedgerRepository::class);
        $user   = $em->find(User::class, $this->userId);

        // Creating a club must not touch account credit. Land is a separate, opt-in purchase.
        self::assertSame(0, $ledger->getCurrentBalance($user));
        self::assertSame(201, $response['status']);
    }

    public function testDuplicateNamesAreStillAllowedForClubsWithNoPlot(): void
    {
        $name = 'Shared Name FC ' . bin2hex(random_bytes(3));

        $this->seedUser();
        $first = $this->post('/api/club/initialize', ['clubName' => $name, 'country' => 'EN']);

        $this->seedUser();
        $second = $this->post('/api/club/initialize', ['clubName' => $name, 'country' => 'EN']);

        // uq_club_name_per_location is PARTIAL on home_location_id IS NOT NULL precisely so it
        // cannot reach clubs founded the old way. If this ever 422s, that index has stopped
        // being partial and every existing save is at risk.
        self::assertSame(201, $first['status']);
        self::assertSame(201, $second['status'], 'clubs with no plot must still be free to share a name');
    }

    public function testAnUnknownCountryStillFailsTheSameWay(): void
    {
        $this->seedUser();

        $response = $this->post('/api/club/initialize', ['clubName' => 'Bad Country FC', 'country' => 'QQ']);

        self::assertSame(422, $response['status']);
        self::assertSame('invalid_country_code', $response['body']['error']);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @param list<array<string, mixed>> $rows */
    private function rowFor(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        self::fail("no country row for $code");
    }

    /**
     * One authenticated POST with a fresh client — loginUser() authenticates exactly one request
     * against this stateless JWT firewall.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(string $uri, array $payload): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->userId);
        $client->loginUser($user, 'api');

        $client->request($uri === '' ? 'GET' : 'POST', $uri, server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode($payload));

        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body'   => json_decode($client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private function seedUser(): void
    {
        self::ensureKernelShutdown();
        static::createClient();

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('legacy-' . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);

        $em->persist($user);
        $em->flush();

        $this->userId = (string) $user->getId();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        static::createClient();

        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $u          = "email LIKE 'legacy-%@test.local'";

        $connection->executeStatement("DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM \"user\" WHERE $u)");
        $connection->executeStatement("DELETE FROM leaderboard_entry WHERE club_id IN (SELECT c.id FROM club c JOIN \"user\" u ON u.id = c.user_id WHERE u.$u)");
        $connection->executeStatement("DELETE FROM club WHERE user_id IN (SELECT id FROM \"user\" WHERE $u)");
        $connection->executeStatement("DELETE FROM \"user\" WHERE $u");

        parent::tearDown();
    }
}
