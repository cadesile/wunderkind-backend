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

    /**
     * The economy block is PURELY ADDITIVE on this public endpoint.
     *
     * Asserted as a key-set DIFF rather than by listing what the block contains, because the
     * claim worth proving is "nothing was lost or changed", not "the new thing is there". A
     * test that enumerated the new keys would pass while a pre-existing one quietly vanished.
     */
    public function testTheEconomyBlockOnlyADDSToStarterConfig(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/starter-config');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        // Every key this endpoint served before 2026-11-12, verbatim.
        $before = [
            'startingBalance', 'userStartingBalanceMinPence', 'userStartingBalanceMaxPence',
            'maxEnrichmentRequestsPerUser', 'quizBonusMultiplier', 'clubFundingMinimumPence',
            'clubFundingProjectionWeeks', 'starterPlayerCount', 'starterManagerCount',
            'starterCoachCount', 'starterScoutCount', 'starterDirectorOfFootballCount',
            'starterFacilityManagerCount', 'starterChairmanCount', 'starterSponsorTier',
            'starterClubTier', 'enabledCountries', 'enabledCountryOptions',
            'leagueAbilityRanges', 'defaultFacilities', 'fanBaseRanges',
            'fanBasePromotionIncrease', 'fanBaseRelegationDecrease', 'tutorial',
        ];

        self::assertSame(
            [],
            array_diff($before, array_keys($data)),
            'starter-config lost a key an old build reads',
        );
        // `fanBaseSharePercent` joined `economy` on 2026-11-19 — the per-tier SHARE of a
        // club's catchment, published BESIDE `fanBaseRanges` and never in place of it. Listed
        // in payload order, which puts it next to the absolute band it accompanies. The
        // `$before` list above is deliberately untouched: the claim this test makes is that
        // nothing an old build reads has gone, and `fanBaseRanges` is still in it.
        self::assertSame(
            ['fanBaseSharePercent', 'economy'],
            array_values(array_diff(array_keys($data), $before)),
            'starter-config gained a key that is neither `fanBaseSharePercent` nor `economy`',
        );
    }

    /**
     * Economy figures are BASE-YEAR, and the inert defaults say so.
     *
     * The one assertion that would catch the worst mistake available here: shipping a non-zero
     * inflation rate or an authored turnover table by accident, which would change what every
     * existing save derives without anyone choosing it.
     */
    public function testTheEconomyBlockShipsSwitchedOff(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/starter-config');

        self::assertResponseIsSuccessful();
        $economy = json_decode((string) $client->getResponse()->getContent(), true)['economy'];

        self::assertSame('legacy', $economy['wageModel']);
        self::assertSame(0, $economy['inflationRatePerSeasonBp']);
        self::assertSame(array_fill(0, 8, 10000), $economy['tierFactorsBp'], 'every tier factor must be parity');
        self::assertSame(array_fill(0, 8, 0), $economy['baselineTurnoverPence'], 'the turnover table must be unauthored');
    }

    /**
     * An old build calls GET /api/game-config with NO `country=` and must get a usable response.
     *
     * The new parameter is optional, and this is the half of that claim that matters: not that
     * passing it works, but that omitting it — which every shipped build does — still resolves.
     */
    public function testGameConfigStillWorksWithNoCountryParameter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/game-config');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        // A few keys an old build reads, to prove the response is intact and not just 200.
        foreach (['cliqueRelationshipThreshold', 'playerFeeMultiplier', 'wageMultiplierTiers',
                  'contractValueRandMin', 'bankruptcyDeductionTiers', 'npcSquadConfig'] as $key) {
            self::assertArrayHasKey($key, $data, "game-config lost $key");
        }

        self::assertNull($data['economyTierBaseline']['country'], 'no country parameter means parity');
        self::assertCount(8, $data['economyTierBaseline']['tiers']);
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
    // ── The init flow, which this guard did not previously reach ────────────────────────
    //
    // World pack SETS changed what POST /api/initialize/starter and
    // POST /api/initialize/league/{tier} do internally: the first now claims a pre-generated
    // set and the second reads tiers out of it. Neither response shape moved, and these three
    // tests are here to keep it that way — the whole mechanism is only backwards compatible
    // because a country with no stock falls back to exactly the old behaviour.

    /**
     * No ready set for the country → the fallback, byte-shape identical to the old behaviour.
     *
     * This is the load-bearing compatibility property of the entire change. A shipped build
     * cannot be taught a new refusal here: the init flow has no client-side escape, so a
     * `503 world_pack_warming` or a `409 no_set_available` would brick every new game in an
     * unstocked country. If this test ever starts needing a new status code, the change is
     * wrong, not the test.
     */
    public function testStarterStillWorksWithNoReadySetAndAnEmptyPool(): void
    {
        $this->seedUser();
        $this->clearSetsFor('EN');

        $this->post('/api/club/initialize', [
            'clubName' => 'Legacy Init FC ' . bin2hex(random_bytes(3)),
            'country'  => 'EN',
        ]);

        // EXACTLY the old call: no body, no new parameters.
        $response = $this->post('/api/initialize/starter', []);

        self::assertSame(
            200,
            $response['status'],
            'a country with no stock must still serve a starter pack — and now with no pool either, '
            . 'since the squad is generated rather than drawn',
        );
        self::assertArrayHasKey('ampStarter', $response['body'], 'initialize/starter lost ampStarter');

        foreach (['players', 'staff', 'scouts'] as $key) {
            self::assertArrayHasKey($key, $response['body']['ampStarter'], "ampStarter lost $key");
        }
        self::assertNotEmpty($response['body']['ampStarter']['players'], 'the live fallback must still fill a squad');
    }

    /**
     * The replay contract, which the claim path must not have broken.
     *
     * `Club::$starterPackJson` is still the only copy of the squad — the Player rows are
     * deleted on consume — so a retry has to hand back the same pack rather than draw again.
     */
    public function testStarterStillReplaysTheSamePackWithReplayedTrue(): void
    {
        $this->seedUser();

        $this->post('/api/club/initialize', [
            'clubName' => 'Legacy Replay FC ' . bin2hex(random_bytes(3)),
            'country'  => 'EN',
        ]);

        $first  = $this->post('/api/initialize/starter', []);
        $second = $this->post('/api/initialize/starter', []);

        self::assertSame(200, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertTrue($second['body']['replayed'] ?? false, 'a retry must still report replayed: true');
        self::assertSame(
            $first['body']['ampStarter'],
            $second['body']['ampStarter'],
            'a replay must return the same squad, not a fresh draw',
        );
    }

    /**
     * The tier endpoint's envelope is unchanged, and a club with no set still gets a real tier.
     *
     * `packVersion` lives INSIDE `data`, which the app had to have pointed out to it once
     * already — so it is asserted in place here rather than at the response root.
     */
    public function testLeagueTierStillReturnsTheSameEnvelopeWithNoSet(): void
    {
        $this->seedUser();
        $this->clearSetsFor('EN');

        $this->post('/api/club/initialize', [
            'clubName' => 'Legacy Tier FC ' . bin2hex(random_bytes(3)),
            'country'  => 'EN',
        ]);
        $this->post('/api/initialize/starter', []);

        $leagues = $this->get('/api/initialize/leagues');
        self::assertSame(200, $leagues['status']);
        self::assertArrayHasKey('leagues', $leagues['body']);
        self::assertArrayHasKey('packVersion', $leagues['body']);

        if ($leagues['body']['leagues'] === []) {
            self::markTestSkipped('no EN leagues seeded in this database');
        }

        $tier     = $leagues['body']['leagues'][0]['tier'];
        $response = $this->post('/api/initialize/league/' . $tier, []);

        self::assertSame(200, $response['status'], 'a club with no set must still be served its tier');
        self::assertArrayHasKey('tier', $response['body'], 'league/{tier} lost tier');
        self::assertArrayHasKey('data', $response['body'], 'league/{tier} lost data');

        foreach (['clubs', 'fixtures', 'packVersion'] as $key) {
            self::assertArrayHasKey($key, $response['body']['data'], "the tier payload lost $key");
        }
    }

    /** Empties a country's set stock, so the fallback path is the one under test. */
    private function clearSetsFor(string $country): void
    {
        self::ensureKernelShutdown();
        static::createClient();

        self::getContainer()->get(\Doctrine\DBAL\Connection::class)->executeStatement(
            'DELETE FROM world_pack_set WHERE country = :c AND claimed_by_club_id IS NULL',
            ['c' => $country],
        );
    }

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

    /**
     * One authenticated GET. `post()` cannot serve this: /api/initialize/leagues is a GET and
     * answers 405 to a POST.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function get(string $uri): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->userId);
        $client->loginUser($user, 'api');

        $client->request('GET', $uri);

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
        // Release any set these clubs claimed first: world_pack_set.claimed_by_club_id is
        // ON DELETE SET NULL so the delete would succeed either way, but leaving the rows
        // CLAIMED by a club that no longer exists would quietly shrink the buffer for every
        // test that runs after this one.
        $connection->executeStatement(
            "DELETE FROM world_pack_set WHERE claimed_by_club_id IN (SELECT c.id FROM club c JOIN \"user\" u ON u.id = c.user_id WHERE u.$u)"
        );
        $connection->executeStatement("UPDATE club SET world_pack_set_id = NULL WHERE user_id IN (SELECT id FROM \"user\" WHERE $u)");
        $connection->executeStatement("DELETE FROM club WHERE user_id IN (SELECT id FROM \"user\" WHERE $u)");
        $connection->executeStatement("DELETE FROM \"user\" WHERE $u");

        parent::tearDown();
    }
}
