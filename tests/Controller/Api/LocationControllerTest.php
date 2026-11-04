<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\User;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\Location\EnrichmentStatus;
use App\Enum\UserLedgerEntryType;
use App\Service\Location\PlotClaimService;
use App\Service\Location\PlotHoldService;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * GET /api/locations, /facets and /{id}.
 *
 * The assertions that matter most here are the two BUDGET ones — a list page must dispatch
 * zero enrichment messages however many un-enriched rows it contains, and a detail read must
 * dispatch exactly one. Every dispatch is a billed external API call, so a regression that
 * moved the lazy trigger onto the list would cost money silently and break nothing visible.
 */
class LocationControllerTest extends WebTestCase
{
    private const COUNTRY = 'ZL';
    private const DRAFT   = 'ZD';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // REQUIRED, not a convenience: the kernel reboots between requests by default, which
        // rebuilds the in-memory transport and would make every "queued nothing" assertion
        // below pass vacuously.
        $this->client->disableReboot();

        $c = self::getContainer();

        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);

        $this->purge();

        foreach ([[self::COUNTRY, 'Locland', CountryProfileStatus::LIVE],
                  [self::DRAFT, 'Draftland', CountryProfileStatus::DRAFT]] as [$code, $name, $status]) {
            $profile = new CountryProfile($code, $name);
            $profile->setStatus($status);
            $this->em->persist($profile);
        }

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    // ───────────────────────── the list ─────────────────────────

    public function testListIsReachableWithoutAuthentication(): void
    {
        $this->location('Alpha');

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY);

        // Proves the ^/api/locations access_control line is present AND ordered ahead of the
        // ^/api catch-all. Without it this is a 401 and the land step is unreachable.
        $this->assertResponseStatusCodeSame(200);
    }

    public function testMissingCountryIs400(): void
    {
        $this->client->request('GET', '/api/locations');

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('country_required', $this->json()['error']);
    }

    public function testUnknownCountryIs404(): void
    {
        $this->client->request('GET', '/api/locations?country=QQ');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('unknown_country', $this->json()['error']);
    }

    public function testCountryThatIsNotLiveReportsItselfEmptyRatherThanLeaking(): void
    {
        $this->location('Hidden', country: self::DRAFT);

        $this->client->request('GET', '/api/locations?country=' . self::DRAFT);

        $this->assertResponseStatusCodeSame(200);
        $data = $this->json();

        $this->assertSame([], $data['locations'], 'a half-built country must serve no locations');
        $this->assertSame('draft', $data['country']['status'], 'and must say why it is empty');
    }

    public function testUnenrichedLocationIsReturnedWithNullFieldsNotOmitted(): void
    {
        $this->location('Unpriced', landPence: null, lat: null, lng: null, population: null);

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY);

        $row = $this->json()['locations'][0];

        // The contract clients are told to rely on: the keys are present and null, so a client
        // renders "loading" rather than crashing on a missing key.
        $this->assertArrayHasKey('lat', $row);
        $this->assertArrayHasKey('landPence', $row);
        $this->assertNull($row['lat']);
        $this->assertNull($row['lng']);
        $this->assertNull($row['landPence']);
        $this->assertSame('pending', $row['enrichmentStatus']);
    }

    public function testListNeverQueuesEnrichmentHoweverManyPendingRows(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->location('Pending' . $i, landPence: null, lat: null, lng: null, population: null);
        }

        $this->transport()->reset();
        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&limit=25');

        $this->assertResponseIsSuccessful();
        $this->assertCount(25, $this->json()['locations']);
        // THE BUDGET GUARD. 25 pending rows, zero dispatches. One per row would be 25 billed
        // API calls for a single page view.
        $this->assertCount(0, $this->transport()->getSent(), 'the list must never queue enrichment');
    }

    public function testPlainListIsPubliclyCacheable(): void
    {
        $this->location('Alpha');

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY);

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('private', $cacheControl);
    }

    public function testAffordabilityVariantIsPrivateAndUncacheable(): void
    {
        $this->location('Alpha');

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&affordability=1');

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        // A shared cache here would serve one player's affordability to another.
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function testAffordabilityIsComputedAgainstLandOnlyAndReportsTheShortfall(): void
    {
        $this->location('Cheap', landPence: 1_000_00);
        $this->location('Dear', landPence: 9_000_000_00);

        $user = $this->user(creditPence: 500_000_00);
        $this->loginAs($user);

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&affordability=1');

        $byTown = $this->byTown($this->json()['locations']);

        $this->assertTrue($byTown['Cheap']['landAffordable']);
        $this->assertSame(0, $byTown['Cheap']['shortfallPence']);

        $this->assertFalse($byTown['Dear']['landAffordable']);
        $this->assertSame(9_000_000_00 - 500_000_00, $byTown['Dear']['shortfallPence']);
        $this->assertSame(500_000_00, $byTown['Dear']['balancePence']);
    }

    public function testAnUnpricedLocationIsNeverReportedAffordable(): void
    {
        $this->location('Unpriced', landPence: null);

        $user = $this->user(creditPence: 100_000_000_00);
        $this->loginAs($user);

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&affordability=1');

        $row = $this->json()['locations'][0];

        // Unknown is not affordable. Reporting it as affordable would promise a price we do
        // not have, and the claim would then fail at the till.
        $this->assertFalse($row['landAffordable']);
        $this->assertNull($row['shortfallPence']);
    }

    public function testAffordableFilterDropsWhatTheCallerCannotBuy(): void
    {
        $this->location('Cheap', landPence: 1_000_00);
        $this->location('Dear', landPence: 9_000_000_00);

        $this->loginAs($this->user(creditPence: 500_000_00));

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&affordable=1');

        $towns = array_column($this->json()['locations'], 'town');

        $this->assertSame(['Cheap'], $towns);
    }

    public function testMaxLandPenceExcludesUnpricedRows(): void
    {
        $this->location('Cheap', landPence: 1_000_00);
        $this->location('Unpriced', landPence: null);

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&maxLandPence=' . 2_000_00);

        $towns = array_column($this->json()['locations'], 'town');

        // An unpriced town might be over the ceiling; it cannot be known to satisfy the filter.
        $this->assertSame(['Cheap'], $towns);
    }

    public function testRegionFilterAcceptsSeveralRegionsAtOnce(): void
    {
        $this->location('Northtown', region: 'North');
        $this->location('Southtown', region: 'South');
        $this->location('Easttown', region: 'East');

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&region=North,South');

        $towns = array_column($this->json()['locations'], 'town');
        sort($towns);

        $this->assertSame(['Northtown', 'Southtown'], $towns);
    }

    public function testSearchMatchesTheNormalisedTownName(): void
    {
        $this->location('München');

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&q=munchen');

        $this->assertCount(1, $this->json()['locations'], 'an accent-free search must find an accented town');
    }

    public function testCursorPagingIsStableAcrossAMidPagePriceEdit(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $i => $town) {
            $this->location($town, landPence: ($i + 1) * 1_000_00);
        }

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&sort=price&limit=2');
        $first = $this->json();
        $this->assertCount(2, $first['locations']);
        $this->assertNotNull($first['nextCursor']);

        // An admin reprices the cheapest town to the most expensive, mid-browse. Under OFFSET
        // paging this reorders the set and the second page skips a town; a keyset cursor names
        // a position, so the rows the client has not seen are still the rows it gets.
        $moved = $this->em->getRepository(Location::class)->findOneBy(['town' => 'A']);
        $moved->setLandPence(99_000_000_00);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/locations?country=' . self::COUNTRY . '&sort=price&limit=2&cursor=' . urlencode($first['nextCursor']),
        );
        $second = $this->json();

        $seen = array_merge(
            array_column($first['locations'], 'town'),
            array_column($second['locations'], 'town'),
        );

        $this->assertNotContains('A', array_column($second['locations'], 'town'), 'a town already shown must not reappear');
        $this->assertCount(count(array_unique($seen)), $seen, 'no town on two pages');
    }

    public function testCursorFromADifferentSortIsRejected(): void
    {
        foreach (['A', 'B', 'C'] as $i => $town) {
            $this->location($town, landPence: ($i + 1) * 1_000_00);
        }

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&sort=price&limit=1');
        $cursor = $this->json()['nextCursor'];

        $this->client->request(
            'GET',
            '/api/locations?country=' . self::COUNTRY . '&sort=town&limit=1&cursor=' . urlencode($cursor),
        );

        // Comparing a price against a town name would return a quietly wrong page.
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('cursor_sort_mismatch', $this->json()['error']);
    }

    public function testMalformedCursorIs400(): void
    {
        $this->location('Alpha');

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&cursor=rubbish');

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_cursor', $this->json()['error']);
    }

    public function testLimitIsClamped(): void
    {
        $this->location('Alpha');

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&limit=999999');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY . '&limit=-3');
        $this->assertResponseIsSuccessful();
    }

    public function testAClubLessClaimStillMarksThePlotTaken(): void
    {
        $plot = $this->plotFor($this->location('Owned', landPence: 1_000_00));
        $user = $this->user(creditPence: 50_000_000_00);

        // No club — land is buyable before one exists, which is the whole reason the purchase
        // endpoints gate on IS_AUTHENTICATED_FULLY rather than ROLE_CLUB.
        $hold = self::getContainer()->get(PlotHoldService::class)->acquire($user, $plot);
        self::getContainer()->get(PlotClaimService::class)->claimFromHold($user, (string) $hold->getId());

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY);
        $row = $this->json()['locations'][0];

        // THE ASSERTION THAT EARNS ITS KEEP. There is no club, so there is no display name —
        // but the plot is sold. A client gating "buy" on claimedBy would offer it for sale.
        $this->assertTrue($row['isClaimed'], 'a club-less claim still takes the plot off the market');
        $this->assertNull($row['claimedBy'], 'and has no name to show, legitimately');
    }

    public function testClaimedByNamesTheClubAndClearsOnRelease(): void
    {
        $plot = $this->plotFor($this->location('Owned', landPence: 1_000_00));
        $user = $this->user(creditPence: 50_000_000_00);

        $club = new Club('Owner FC', $user);
        $this->em->persist($club);
        $this->em->flush();

        $hold  = self::getContainer()->get(PlotHoldService::class)->acquire($user, $plot);
        $claim = self::getContainer()->get(PlotClaimService::class)
            ->claimFromHold($user, (string) $hold->getId());

        self::getContainer()->get(PlotClaimService::class)->attachClub($claim, $club);

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY);
        $row = $this->json()['locations'][0];

        $this->assertTrue($row['isClaimed']);
        $this->assertSame('Owner FC', $row['claimedBy']);

        $this->connection->executeStatement(
            "UPDATE plot_claim SET released_at = now(), release_reason = 'sold_back' WHERE id = :id",
            ['id' => (string) $claim->getId()],
        );
        $this->em->clear();

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY);
        $row = $this->json()['locations'][0];

        $this->assertFalse($row['isClaimed'], 'a released plot is back on the market');
        $this->assertNull($row['claimedBy']);
    }

    // ───────────────────────── facets ─────────────────────────

    public function testFacetsReportRegionCountsAndThePriceRange(): void
    {
        $this->location('Northtown', region: 'North', landPence: 1_000_00);
        $this->location('Northville', region: 'North', landPence: 5_000_00);
        $this->location('Southtown', region: 'South', landPence: null);

        $this->client->request('GET', '/api/locations/facets?country=' . self::COUNTRY);

        $data = $this->json();

        $this->assertSame(
            [['region' => 'North', 'count' => 2], ['region' => 'South', 'count' => 1]],
            $data['regions'],
        );
        $this->assertSame(1_000_00, $data['landPenceMin']);
        $this->assertSame(5_000_00, $data['landPenceMax']);
        $this->assertSame(2, $data['pricedCount'], 'the unpriced town is counted but not priced');
        $this->assertSame(3, $data['totalCount']);
    }

    public function testFacetsRouteIsNotSwallowedByTheDetailRoute(): void
    {
        $this->client->request('GET', '/api/locations/facets?country=' . self::COUNTRY);

        // /facets is declared before /{id}; if that ever flips, this returns location_not_found.
        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('regions', $this->json());
    }

    // ───────────────────────── detail ─────────────────────────

    public function testDetailOfAPendingLocationQueuesExactlyOneEnrichment(): void
    {
        $location = $this->location('Pendingtown', landPence: null, lat: null, lng: null, population: null);

        $this->transport()->reset();
        $this->client->request('GET', '/api/locations/' . $location->getId());

        $this->assertResponseIsSuccessful();
        $data = $this->json();

        $this->assertNull($data['lat']);
        $this->assertSame('queued', $data['enrichmentStatus'], 'the client is told it is loading');
        $this->assertCount(1, $this->transport()->getSent(), 'one read, one billed call');
    }

    public function testASecondDetailReadDoesNotQueueAgain(): void
    {
        $location = $this->location('Pendingtown', landPence: null, lat: null, lng: null, population: null);

        $this->client->request('GET', '/api/locations/' . $location->getId());
        $this->transport()->reset();

        $this->em->clear();
        $this->client->request('GET', '/api/locations/' . $location->getId());

        // The lease is what collapses a herd of readers into one API call; a second reader
        // inside the lease window must see the status and dispatch nothing.
        $this->assertCount(0, $this->transport()->getSent());
    }

    public function testPendingDetailIsNotPubliclyCacheable(): void
    {
        $location = $this->location('Pendingtown', landPence: null, lat: null, lng: null, population: null);

        $this->client->request('GET', '/api/locations/' . $location->getId());

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        // A cached "queued" would be polled for ever and the client would never see the result.
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function testEnrichedDetailIsPubliclyCacheable(): void
    {
        $location = $this->location('Donetown');
        $location->setEnrichmentStatus(EnrichmentStatus::ENRICHED);
        $location->setEnrichedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->client->request('GET', '/api/locations/' . $location->getId());

        $this->assertStringContainsString(
            'public',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    public function testDetailCarriesRivalsAndTheListDoesNot(): void
    {
        $location = $this->location('Alpha');

        $this->client->request('GET', '/api/locations/' . $location->getId());
        $this->assertArrayHasKey('rivals', $this->json(), 'rivals are a detail concern');

        $this->client->request('GET', '/api/locations?country=' . self::COUNTRY);
        $this->assertArrayNotHasKey(
            'rivals',
            $this->json()['locations'][0],
            'four rows per location across a 200-row page is 800 rows nobody reads',
        );
    }

    public function testUnknownLocationIs404(): void
    {
        $this->client->request('GET', '/api/locations/01932000-0000-7000-8000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.enrichment');
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private function byTown(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['town']] = $row;
        }

        return $out;
    }

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user, 'api');
    }

    private function location(
        string $town,
        string $country = self::COUNTRY,
        string $region = 'R',
        ?int $landPence = 2_000_00,
        ?float $lat = 53.0,
        ?float $lng = -2.0,
        ?int $population = 100_000,
    ): Location {
        $profile  = $this->em->find(CountryProfile::class, $country);
        $location = new Location(
            $profile,
            $town,
            strtolower($country . '-' . $town),
            \App\Service\ClubNameNormalizer::normalize($town),
        );
        $location->setRegion($region);

        if ($population !== null) {
            $location->setPopulation($population);
        }

        if ($lat !== null && $lng !== null) {
            $location->setCoordinates($lat, $lng);
        }

        if ($landPence !== null) {
            $location->setLandPence($landPence);
            $location->setLandValuedAt(new \DateTimeImmutable());
        }

        $this->em->persist($location);
        $this->em->persist(new LandPlot($location, 1));
        $this->em->flush();

        return $location;
    }

    private function plotFor(Location $location): LandPlot
    {
        return $this->em->getRepository(LandPlot::class)->findOneBy(['location' => $location]);
    }

    private function user(int $creditPence = 0): User
    {
        $user = new User('loc-' . bin2hex(random_bytes(5)) . '@test.local');
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
                'loc-grant-' . bin2hex(random_bytes(5)),
            );
        }

        return $user;
    }

    private function purge(): void
    {
        $c      = $this->connection;
        $codes  = [self::COUNTRY, self::DRAFT];
        $inList = "('" . implode("','", $codes) . "')";

        $c->executeStatement("DELETE FROM plot_claim_valuation WHERE claim_id IN (SELECT pc.id FROM plot_claim pc JOIN location l ON l.id = pc.location_id WHERE l.country_code IN $inList)");
        $c->executeStatement("DELETE FROM plot_claim WHERE location_id IN (SELECT id FROM location WHERE country_code IN $inList)");
        $c->executeStatement("DELETE FROM plot_hold WHERE plot_id IN (SELECT p.id FROM land_plot p JOIN location l ON l.id = p.location_id WHERE l.country_code IN $inList)");
        $c->executeStatement("DELETE FROM location_rival WHERE location_id IN (SELECT id FROM location WHERE country_code IN $inList)");
        $c->executeStatement("DELETE FROM location_enrichment_attempt WHERE location_id IN (SELECT id FROM location WHERE country_code IN $inList)");
        $c->executeStatement("DELETE FROM stadium_estimate_input WHERE estimate_id IN (SELECT e.id FROM stadium_estimate e JOIN location l ON l.id = e.location_id WHERE l.country_code IN $inList)");
        $c->executeStatement("DELETE FROM stadium_estimate WHERE location_id IN (SELECT id FROM location WHERE country_code IN $inList)");
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code IN $inList)");
        $c->executeStatement("DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'loc-%@test.local')");
        $c->executeStatement("DELETE FROM club WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'loc-%@test.local')");
        $c->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'loc-%@test.local'");
        $c->executeStatement("DELETE FROM location WHERE country_code IN $inList");
        $c->executeStatement("DELETE FROM country_profile WHERE code IN $inList");
    }
}
