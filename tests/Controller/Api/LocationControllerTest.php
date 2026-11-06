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
    private string $currentUserId = '';
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
        $this->restoreEnrichmentCap();

        // Every location endpoint requires authentication — the catalogue is reached from
        // inside the game, and POST /{id}/enrich spends money. Tests that care about a specific
        // account's balance call loginAs() with their own user.
        $this->currentUserId = (string) $this->user()->getId();

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
        $this->restoreEnrichmentCap();

        parent::tearDown();
    }

    // ───────────────────────── the list ─────────────────────────

    public function testListIsReachableWithoutAuthentication(): void
    {
        $this->location('Alpha');

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY);

        // Proves the ^/api/locations access_control line is present AND ordered ahead of the
        // ^/api catch-all. Without it this is a 401 and the land step is unreachable.
        $this->assertResponseStatusCodeSame(200);
    }

    public function testMissingCountryIs400(): void
    {
        $this->authedRequest('GET', '/api/locations');

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('country_required', $this->json()['error']);
    }

    public function testUnknownCountryIs404(): void
    {
        $this->authedRequest('GET', '/api/locations?country=QQ');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('unknown_country', $this->json()['error']);
    }

    public function testCountryThatIsNotLiveReportsItselfEmptyRatherThanLeaking(): void
    {
        $this->location('Hidden', country: self::DRAFT);

        $this->authedRequest('GET', '/api/locations?country=' . self::DRAFT);

        $this->assertResponseStatusCodeSame(200);
        $data = $this->json();

        $this->assertSame([], $data['locations'], 'a half-built country must serve no locations');
        $this->assertSame('draft', $data['country']['status'], 'and must say why it is empty');
    }

    public function testUnenrichedLocationIsReturnedWithNullFieldsNotOmitted(): void
    {
        $this->location('Unpriced', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY);

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

        $this->prepareClient();
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

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY);

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('private', $cacheControl);
    }

    public function testAffordabilityVariantIsPrivateAndUncacheable(): void
    {
        $this->location('Alpha');

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&affordability=1');

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

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&affordability=1');

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

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&affordability=1');

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

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&affordable=1');

        $towns = array_column($this->json()['locations'], 'town');

        $this->assertSame(['Cheap'], $towns);
    }

    public function testMaxLandPenceExcludesUnpricedRows(): void
    {
        $this->location('Cheap', landPence: 1_000_00);
        $this->location('Unpriced', landPence: null);

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&maxLandPence=' . 2_000_00);

        $towns = array_column($this->json()['locations'], 'town');

        // An unpriced town might be over the ceiling; it cannot be known to satisfy the filter.
        $this->assertSame(['Cheap'], $towns);
    }

    public function testRegionFilterAcceptsSeveralRegionsAtOnce(): void
    {
        $this->location('Northtown', region: 'North');
        $this->location('Southtown', region: 'South');
        $this->location('Easttown', region: 'East');

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&region=North,South');

        $towns = array_column($this->json()['locations'], 'town');
        sort($towns);

        $this->assertSame(['Northtown', 'Southtown'], $towns);
    }

    public function testSearchMatchesTheNormalisedTownName(): void
    {
        $this->location('München');

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&q=munchen');

        $this->assertCount(1, $this->json()['locations'], 'an accent-free search must find an accented town');
    }

    public function testCursorPagingIsStableAcrossAMidPagePriceEdit(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $i => $town) {
            $this->location($town, landPence: ($i + 1) * 1_000_00);
        }

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&sort=price&limit=2');
        $first = $this->json();
        $this->assertCount(2, $first['locations']);
        $this->assertNotNull($first['nextCursor']);

        // An admin reprices the cheapest town to the most expensive, mid-browse. Under OFFSET
        // paging this reorders the set and the second page skips a town; a keyset cursor names
        // a position, so the rows the client has not seen are still the rows it gets.
        $moved = $this->em->getRepository(Location::class)->findOneBy(['town' => 'A']);
        $moved->setLandPence(99_000_000_00);
        $this->em->flush();

        $this->authedRequest(
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

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&sort=price&limit=1');
        $cursor = $this->json()['nextCursor'];

        $this->authedRequest(
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

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&cursor=rubbish');

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_cursor', $this->json()['error']);
    }

    public function testLimitIsClamped(): void
    {
        $this->location('Alpha');

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&limit=999999');
        $this->assertResponseIsSuccessful();

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&limit=-3');
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

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY);
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

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY);
        $row = $this->json()['locations'][0];

        $this->assertTrue($row['isClaimed']);
        $this->assertSame('Owner FC', $row['claimedBy']);

        $this->connection->executeStatement(
            "UPDATE plot_claim SET released_at = now(), release_reason = 'sold_back' WHERE id = :id",
            ['id' => (string) $claim->getId()],
        );
        $this->em->clear();

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY);
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

        $this->authedRequest('GET', '/api/locations/facets?country=' . self::COUNTRY);

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

    /**
     * Region-less towns are offered as a named, selectable bucket.
     *
     * A region arrives with enrichment, so most of a catalogue has none. Those rows used to
     * come back as a facet with an EMPTY name, which a region picker cannot render and so
     * drops — leaving the only un-enriched towns in the game reachable by search alone, and
     * therefore un-enrichable, since a player has to find a town to ask for its valuation.
     */
    public function testFacetsLabelTheRegionlessTownsRatherThanReturningAnEmptyName(): void
    {
        $this->location('Northtown', region: 'North');
        $this->location('Barename', region: '', landPence: null, lat: null, lng: null);

        $this->authedRequest('GET', '/api/locations/facets?country=' . self::COUNTRY);

        $byRegion = [];
        foreach ($this->json()['regions'] as $row) {
            $byRegion[$row['region']] = $row['count'];
        }

        $this->assertSame(1, $byRegion['Unassigned'] ?? null, 'the region-less town needs a usable label');
        $this->assertSame(1, $byRegion['North'] ?? null);
        $this->assertArrayNotHasKey('', $byRegion, 'an empty facet name is unrenderable');
    }

    /**
     * And the label is accepted back as a filter.
     *
     * THE HALF THAT MAKES THE LABEL WORTH ANYTHING. `region IN ('Unassigned')` matches nothing,
     * because the stored value is `''` — so without this translation the facet would offer a
     * bucket that is selectable and always empty, which is worse than not offering it at all.
     */
    public function testFilteringByUnassignedReturnsTheRegionlessTowns(): void
    {
        $this->location('Northtown', region: 'North');
        $this->location('Barename', region: '', landPence: null, lat: null, lng: null);

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&region=Unassigned');

        $towns = array_column($this->json()['locations'], 'town');
        $this->assertSame(['Barename'], $towns);
    }

    /** A named region still excludes them — the bucket is opt-in, not a widening of every filter. */
    public function testFilteringByANamedRegionStillExcludesTheRegionlessTowns(): void
    {
        $this->location('Northtown', region: 'North');
        $this->location('Barename', region: '', landPence: null, lat: null, lng: null);

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&region=North');

        $this->assertSame(['Northtown'], array_column($this->json()['locations'], 'town'));
    }

    /** Combined with a named region, so "North plus the ones we do not know yet" works. */
    public function testUnassignedCombinesWithNamedRegions(): void
    {
        $this->location('Northtown', region: 'North');
        $this->location('Southtown', region: 'South');
        $this->location('Barename', region: '', landPence: null, lat: null, lng: null);

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY . '&region=North,Unassigned');

        $towns = array_column($this->json()['locations'], 'town');
        sort($towns);
        $this->assertSame(['Barename', 'Northtown'], $towns);
    }

    public function testFacetsRouteIsNotSwallowedByTheDetailRoute(): void
    {
        $this->authedRequest('GET', '/api/locations/facets?country=' . self::COUNTRY);

        // /facets is declared before /{id}; if that ever flips, this returns location_not_found.
        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('regions', $this->json());
    }

    // ───────────────────────── detail ─────────────────────────

    public function testReadingAPendingTownCostsNothing(): void
    {
        $location = $this->location('Pendingtown', landPence: null, lat: null, lng: null, population: null);

        $this->prepareClient();
        $this->transport()->reset();
        $this->client->request('GET', '/api/locations/' . $location->getId());

        $this->assertResponseIsSuccessful();
        $data = $this->json();

        $this->assertNull($data['lat']);
        $this->assertSame('pending', $data['enrichmentStatus'], 'reading does not change anything');

        // THE WHOLE REASON THE TRIGGER MOVED TO A POST. A GET can be issued by a crawler, a
        // link preview, a prefetch or a double-tap, and every one of those used to spend money.
        $this->assertCount(0, $this->transport()->getSent(), 'a read must never be billable');
    }

    public function testPostingToEnrichQueuesExactlyOneCall(): void
    {
        $location = $this->location('Pendingtown', landPence: null, lat: null, lng: null, population: null);

        $this->prepareClient();
        $this->transport()->reset();
        $this->client->request('POST', '/api/locations/' . $location->getId() . '/enrich');

        $this->assertResponseStatusCodeSame(202);
        $data = $this->json();

        $this->assertTrue($data['queued']);
        $this->assertSame('queued', $data['enrichmentStatus']);
        $this->assertStringContainsString((string) $location->getId(), $data['pollUrl']);
        $this->assertCount(1, $this->transport()->getSent(), 'one request, one billed call');
    }

    public function testEnrichingTwiceStillQueuesOnlyOneCall(): void
    {
        $location = $this->location('Pendingtown', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('POST', '/api/locations/' . $location->getId() . '/enrich');

        $this->prepareClient();
        $this->transport()->reset();
        $this->client->request('POST', '/api/locations/' . $location->getId() . '/enrich');

        // The claim is an atomic conditional UPDATE, so a herd of callers produces ONE job.
        // `queued: false` means somebody else got there first — the same outcome for this caller,
        // which is why both answers are 202.
        $this->assertResponseStatusCodeSame(202);
        $this->assertFalse($this->json()['queued']);
        $this->assertCount(0, $this->transport()->getSent(), 'a second request must not re-bill');
    }

    public function testEnrichingAnAlreadyPricedTownIsANoOp(): void
    {
        $location = $this->location('Donetown');
        $location->setEnrichmentStatus(EnrichmentStatus::ENRICHED);
        $this->em->flush();

        $this->prepareClient();
        $this->transport()->reset();
        $this->client->request('POST', '/api/locations/' . $location->getId() . '/enrich');

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->json()['queued']);
        $this->assertCount(0, $this->transport()->getSent());
    }

    public function testEnrichingATownThatNeedsAHumanIsRefused(): void
    {
        $location = $this->location('Problemtown', landPence: null, lat: null, lng: null, population: null);
        $location->setEnrichmentStatus(EnrichmentStatus::FAILED);
        $this->em->flush();

        $this->prepareClient();
        $this->transport()->reset();
        $this->client->request('POST', '/api/locations/' . $location->getId() . '/enrich');

        // Terminal. Re-queueing would burn a billed call on every retry of a town that cannot be
        // resolved, so the client is told to stop rather than left polling.
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('enrichment_needs_admin', $this->json()['error']);
        $this->assertCount(0, $this->transport()->getSent());
    }

    // ───────────────────── the per-account valuation quota ─────────────────────

    public function testTheQuotaIsChargedOnlyWhenACallIsActuallyBilled(): void
    {
        $this->setEnrichmentCap(5);

        $a = $this->location('Quotatown', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('POST', '/api/locations/' . $a->getId() . '/enrich');
        $this->assertSame(1, $this->enrichmentCountOf($this->currentUserId), 'a dispatch is charged');

        // The same town again: somebody is already paying for it, so this one bills nothing and
        // must not take the player's allowance with it. This is the double-tap / retry case.
        $this->authedRequest('POST', '/api/locations/' . $a->getId() . '/enrich');
        $this->assertResponseStatusCodeSame(202);
        $this->assertFalse($this->json()['queued']);
        $this->assertSame(1, $this->enrichmentCountOf($this->currentUserId), 'a no-op is free');
    }

    /**
     * The 202 carries the allowance at the top level too, so one tap needs no second call.
     *
     * Same reason as the 429's assertion: the shipped client reads `res.enrichmentCount`, and
     * the `quota` block alone left that undefined. Both positions are asserted because both
     * have a reader.
     */
    public function testTheQueuedResponseCarriesTheAllowanceAtTheTopLevel(): void
    {
        $this->setEnrichmentCap(5);

        $location = $this->location('Toplevel', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('POST', '/api/locations/' . $location->getId() . '/enrich');

        $this->assertResponseStatusCodeSame(202);
        $body = $this->json();

        $this->assertSame(1, $body['enrichmentCount'], 'top-level, where allowance() looks');
        $this->assertSame(5, $body['enrichmentCountMax']);
        $this->assertSame(1, $body['quota']['used'], 'and the nested block is unchanged');
        $this->assertSame(4, $body['quota']['remaining']);
    }

    /**
     * An unlimited allowance sends a NULL ceiling, and that is the one case the alias cannot
     * express in the client's own terms.
     *
     * Asserted so the behaviour is pinned rather than incidental: `0` would read as "none
     * allowed" and disable the button, and a client applying `?? DEFAULT` to null inverts
     * unlimited into the smallest cap it has. Dev therefore carries a real ceiling (25) so this
     * path is not what the app meets first — see docs/api/locations.md.
     */
    public function testAnUnlimitedAllowanceSendsANullCeilingAtTheTopLevel(): void
    {
        $this->setEnrichmentCap(0);

        $location = $this->location('Unlimitedtown', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('POST', '/api/locations/' . $location->getId() . '/enrich');

        $this->assertResponseStatusCodeSame(202);
        $body = $this->json();

        $this->assertSame(1, $body['enrichmentCount']);
        $this->assertNull($body['enrichmentCountMax'], 'unlimited is null, never 0');
        $this->assertTrue($body['quota']['unlimited']);
    }

    public function testAnExhaustedAccountIsRefusedAndNothingIsBilled(): void
    {
        $this->setEnrichmentCap(1);

        $first  = $this->location('Firsttown', landPence: null, lat: null, lng: null, population: null);
        $second = $this->location('Secondtown', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('POST', '/api/locations/' . $first->getId() . '/enrich');
        $this->assertResponseStatusCodeSame(202);

        $this->prepareClient();
        $this->transport()->reset();
        $this->client->request('POST', '/api/locations/' . $second->getId() . '/enrich');

        $this->assertResponseStatusCodeSame(429);
        $this->assertSame('enrichment_quota_exhausted', $this->json()['error']);
        // A second spelling of the same refusal. `error` stays canonical; `code` carries the
        // spelling the app's switch is written against. The app reads its ApiError.code from
        // `body.error` alone, so this does not make its branch fire on its own — it has to
        // accept either string. Asserted so the pairing cannot silently disappear.
        $this->assertSame('enrichment_limited', $this->json()['code']);

        // AND THE ALIASES AT THE TOP LEVEL, not only inside `quota`. The shipped client's
        // allowance() reads res.enrichmentCount; nested-only it reads undefined and bails,
        // leaving VALUE TOWN enabled to re-429 on every tap. That is what the first version of
        // this change shipped, and this assertion is what would have caught it.
        $body = $this->json();
        $this->assertArrayHasKey('enrichmentCount', $body, 'alias must be top-level, not only under quota');
        $this->assertSame(1, $body['enrichmentCount']);
        $this->assertSame(1, $body['enrichmentCountMax']);
        // The nested block stays, for readers already using it.
        $this->assertSame(1, $body['quota']['used']);
        $this->assertCount(0, $this->transport()->getSent(), 'a refusal must not dispatch');
        $this->assertSame(1, $this->enrichmentCountOf($this->currentUserId), 'nor charge');
    }

    /**
     * An exhausted allowance must not make the catalogue unusable.
     *
     * The refusal is checked AFTER the cheap answers for exactly this reason: reading towns and
     * opening already-priced ones cost nothing, so they keep working. Only a NEW valuation is
     * refused.
     */
    public function testAnExhaustedAccountCanStillBrowseAndOpenPricedTowns(): void
    {
        $this->setEnrichmentCap(1);

        $spend = $this->location('Spendtown', landPence: null, lat: null, lng: null, population: null);
        $this->authedRequest('POST', '/api/locations/' . $spend->getId() . '/enrich');

        $priced = $this->location('Pricedtown');
        $priced->setEnrichmentStatus(EnrichmentStatus::ENRICHED);
        $this->em->flush();

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY);
        $this->assertResponseIsSuccessful();

        $this->authedRequest('POST', '/api/locations/' . $priced->getId() . '/enrich');
        $this->assertResponseIsSuccessful();
        $this->assertSame('enriched', $this->json()['enrichmentStatus']);
    }

    /**
     * A cap of zero is UNLIMITED, not "nobody may enrich".
     *
     * Load-bearing for the deploy: the column lands on a database where enrichment already
     * works, and the migration sets the live row to 0 precisely so the endpoint keeps behaving
     * as it does today until an admin picks a number. If this ever flips, deploying the feature
     * silently switches enrichment off.
     */
    public function testACapOfZeroMeansUnlimited(): void
    {
        $this->setEnrichmentCap(0);

        $a = $this->location('Freetown', landPence: null, lat: null, lng: null, population: null);
        $b = $this->location('Freetwo', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('POST', '/api/locations/' . $a->getId() . '/enrich');
        $this->assertResponseStatusCodeSame(202);

        $this->authedRequest('POST', '/api/locations/' . $b->getId() . '/enrich');
        $this->assertResponseStatusCodeSame(202);

        $this->authedRequest('GET', '/api/account/enrichment-quota');
        $data = $this->json();

        $this->assertTrue($data['unlimited']);
        $this->assertFalse($data['exhausted']);
        $this->assertNull($data['remaining'], 'no sentinel number a client could render');
        $this->assertSame(2, $data['used'], 'the meter still counts, it just does not gate');

        // THE ALIAS CANNOT SAY "UNLIMITED" IN THE CLIENT'S OWN TERMS, so it says null. The
        // ceiling is stored as 0 meaning unlimited; passing that through as the max would read
        // as "none allowed" and disable the button for every account on dev, where 0 is the
        // deployed default. A client treating a null ceiling as "no limit" is correct.
        $this->assertSame(2, $data['enrichmentCount']);
        $this->assertNull($data['enrichmentCountMax'], 'unlimited is null, never 0');
    }

    public function testTheQuotaEndpointReportsTheAccountsStanding(): void
    {
        $this->setEnrichmentCap(3);

        $location = $this->location('Metertown', landPence: null, lat: null, lng: null, population: null);
        $this->authedRequest('POST', '/api/locations/' . $location->getId() . '/enrich');

        $this->authedRequest('GET', '/api/account/enrichment-quota');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            [
                'used' => 1, 'max' => 3, 'remaining' => 2, 'unlimited' => false, 'exhausted' => false,
                // ALIASES the shipped client reads — its allowance() bails when
                // `enrichmentCount` is null, so without these the VALUATIONS LEFT strip never
                // renders and VALUE TOWN stays permanently enabled. Asserted exhaustively, and
                // on purpose: these are the field names an app build already in the wild
                // depends on, so dropping one is a client regression, not a tidy-up.
                'enrichmentCount' => 1, 'enrichmentCountMax' => 3,
            ],
            $this->json(),
        );

        // One account's spend must never be served to another from a shared cache.
        $this->assertResponseHeaderSame('Cache-Control', 'max-age=0, private');
    }

    /** One account's spend must not bleed into another's allowance. */
    public function testTheQuotaIsPerAccount(): void
    {
        $this->setEnrichmentCap(1);

        $location = $this->location('Sharedtown', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('POST', '/api/locations/' . $location->getId() . '/enrich');
        $this->assertResponseStatusCodeSame(202);

        $other = $this->location('Othertown', landPence: null, lat: null, lng: null, population: null);
        $this->loginAs($this->user());

        $this->authedRequest('POST', '/api/locations/' . $other->getId() . '/enrich');
        $this->assertResponseStatusCodeSame(202);
        $this->assertSame(1, $this->enrichmentCountOf($this->currentUserId));
    }

    /**
     * An admin LOWERING the cap below what an account already spent must refuse it, not wrap
     * around into giving it budget back. This is why the check is `>=` and not `===`.
     */
    public function testLoweringTheCapBelowAnAccountsSpendRefusesIt(): void
    {
        $this->setEnrichmentCap(5);

        $first = $this->location('Beforetown', landPence: null, lat: null, lng: null, population: null);
        $this->authedRequest('POST', '/api/locations/' . $first->getId() . '/enrich');
        $this->authedRequest('GET', '/api/account/enrichment-quota');
        $this->assertSame(1, $this->json()['used']);

        $this->setEnrichmentCap(1);

        $second = $this->location('Aftertown', landPence: null, lat: null, lng: null, population: null);
        $this->authedRequest('POST', '/api/locations/' . $second->getId() . '/enrich');
        $this->assertResponseStatusCodeSame(429);
    }

    public function testTheQuotaEndpointRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/api/account/enrichment-quota');

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testEnrichingRequiresAuthentication(): void
    {
        $location = $this->location('Pendingtown', landPence: null, lat: null, lng: null, population: null);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('POST', '/api/locations/' . $location->getId() . '/enrich');

        // Browsing is one thing; spending is another. Without this a script could walk the whole
        // catalogue and commit the entire enrichment budget.
        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testASecondDetailReadDoesNotQueueAgain(): void
    {
        $location = $this->location('Pendingtown', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('GET', '/api/locations/' . $location->getId());
        $this->transport()->reset();

        $this->em->clear();
        $this->authedRequest('GET', '/api/locations/' . $location->getId());

        // The lease is what collapses a herd of readers into one API call; a second reader
        // inside the lease window must see the status and dispatch nothing.
        $this->assertCount(0, $this->transport()->getSent());
    }

    public function testPendingDetailIsNotPubliclyCacheable(): void
    {
        $location = $this->location('Pendingtown', landPence: null, lat: null, lng: null, population: null);

        $this->authedRequest('GET', '/api/locations/' . $location->getId());

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

        $this->authedRequest('GET', '/api/locations/' . $location->getId());

        $this->assertStringContainsString(
            'public',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    public function testDetailCarriesRivalsAndTheListDoesNot(): void
    {
        $location = $this->location('Alpha');

        $this->authedRequest('GET', '/api/locations/' . $location->getId());
        $this->assertArrayHasKey('rivals', $this->json(), 'rivals are a detail concern');

        $this->authedRequest('GET', '/api/locations?country=' . self::COUNTRY);
        $this->assertArrayNotHasKey(
            'rivals',
            $this->json()['locations'][0],
            'four rows per location across a 200-row page is 800 rows nobody reads',
        );
    }

    public function testUnknownLocationIs404(): void
    {
        $this->authedRequest('GET', '/api/locations/01932000-0000-7000-8000-000000000000');

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
        $this->currentUserId = (string) $user->getId();
    }

    /**
     * A fresh, authenticated client, ready for exactly ONE request.
     *
     * loginUser() authenticates a single request against this stateless JWT firewall and the
     * client never recovers — the next one is a 401, and calling loginUser() again does not
     * help. So every request needs a new client, which reboots the kernel and therefore REBUILDS
     * the in-memory transport.
     *
     * That is why this is separate from authedRequest(): a test asserting on the transport must
     * prepare the client FIRST, then reset the transport, then make its single request, so all
     * three touch the same container. Resetting before the client exists would reset a transport
     * that is about to be thrown away, and every "queued nothing" assertion would pass vacuously.
     */
    private function prepareClient(): void
    {
        self::ensureKernelShutdown();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $c = self::getContainer();

        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);

        $this->client->loginUser($this->em->find(User::class, $this->currentUserId), 'api');
    }

    /** @param array<string, mixed> $parameters */
    private function authedRequest(string $method, string $uri, array $parameters = []): void
    {
        $this->prepareClient();
        $this->client->request($method, $uri, $parameters);
    }

    /**
     * The admin-set ceiling. 0 is unlimited.
     *
     * `starter_config` is a SINGLETON ROW that purge() does not touch, so whatever a test leaves
     * here is what the next test file sees. tearDown() puts it back — see restoreEnrichmentCap().
     */
    private function setEnrichmentCap(int $max): void
    {
        $this->connection->executeStatement(
            'UPDATE starter_config SET max_enrichment_requests_per_user = :max',
            ['max' => $max],
        );

        // The config is read through a repository that may already hold the row; a fresh client
        // per request means a fresh container, but the connection above is shared, so clear to
        // be certain the next read sees the new number.
        $this->em->clear();
    }

    /**
     * Put the shared config row back to unlimited.
     *
     * Not an optimisation — a leaked ceiling of 1 would make an unrelated test file's first
     * enrichment pass and its second 429, with nothing in that file mentioning a quota. The
     * cheapest version of the leaked-row bug this suite has already been bitten by once.
     */
    private function restoreEnrichmentCap(): void
    {
        $this->connection->executeStatement('UPDATE starter_config SET max_enrichment_requests_per_user = 0');
    }

    private function enrichmentCountOf(string $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT enrichment_count FROM "user" WHERE id = :id',
            ['id' => $userId],
        );
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
