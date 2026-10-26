<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\LiveTelemetrySnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers GET /api/telemetry — the public JSON projection of the LiveTelemetrySnapshot
 * singleton that the React Native app consumes.
 *
 * The important assertions here are the NEGATIVE ones. The endpoint is not a verbatim
 * dump of the stored snapshot: `at`, `time` and `dossier` are all present in the
 * persisted `recent_events` JSON and must never reach a client. None of that is
 * observable from local data (the dev database's syncs all fall outside the 24h window,
 * so a real `app:telemetry:generate` run produces an empty feed), hence a crafted
 * snapshot here rather than a fixture-free smoke test.
 */
class TelemetryControllerTest extends WebTestCase
{
    /** The shape LiveTelemetryService::event() actually persists, including the junk. */
    private function storedEvent(): array
    {
        return [
            // Rendered at generation time and therefore stale on arrival — must be dropped.
            'time'          => '3 hours ago',
            'text'          => 'Dulwich Hamlet promoted to Tier 6',
            'category'      => 'PYRAMID',
            'categoryLabel' => '[PYRAMID // MOVEMENT]',
            'club'          => 'Dulwich Hamlet',
            'detail'        => 'Finished 1st on 92 points.',
            'amountExact'   => '£246,300',
            'timestampIso'  => '2026-10-21T19:15:00+00:00',
            'meta'          => ['tierFrom' => 7, 'tierTo' => 6],
            'clubBadge'     => ['kit' => 'stripes', 'primary' => '#ff0000'],
            'clubId'        => 'club-abc-123',
            // Bulk payload for the web boardroom overlay — excluded by product decision.
            'dossier' => ['fixtures' => [['h' => 1, 'a' => 0]], 'bestPlayer' => ['name' => 'A. Player']],
            // A real DateTimeImmutable inside a json column: json_encode turns this into
            // {"date":…,"timezone_type":3,"timezone":…}, a PHP artifact.
            'at' => new \DateTimeImmutable('2026-10-21T19:15:00+00:00'),
        ];
    }

    private function seedSnapshot(EntityManagerInterface $em): void
    {
        $snapshot = $em->getRepository(LiveTelemetrySnapshot::class)->findOneBy([]) ?? new LiveTelemetrySnapshot();
        $snapshot->update(
            fixturesSimulated: 42,
            capitalDeployedPence: 24_630_000,
            wins: 7,
            draws: 3,
            losses: 2,
            activeClubs: 11,
            weeksPlayed: 48,
            recentEvents: [$this->storedEvent()],
            communityMoraleDelta: null,
            totalGoals: 31,
            totalMatches: 12,
            tableTurnover: 6,
        );
        $em->persist($snapshot);
        $em->flush();
    }

    public function testItIsPubliclyReachableWithoutAuthentication(): void
    {
        $client = static::createClient();
        $this->seedSnapshot(self::getContainer()->get(EntityManagerInterface::class));

        // No loginUser() anywhere: /api/* is behind the stateless JWT firewall, so a 401
        // here would mean the security.yaml PUBLIC_ACCESS entry is missing.
        $client->request('GET', '/api/telemetry');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testItDropsTheKeysThatMustNeverReachAClient(): void
    {
        $client = static::createClient();
        $this->seedSnapshot(self::getContainer()->get(EntityManagerInterface::class));

        $client->request('GET', '/api/telemetry');
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['feed']);
        $item = $payload['feed'][0];

        self::assertArrayNotHasKey('at', $item, 'The persisted DateTimeImmutable must not be serialized to a client.');
        self::assertArrayNotHasKey('time', $item, 'The generation-time relative string is stale on arrival.');
        self::assertArrayNotHasKey('dossier', $item, 'The boardroom dossier is excluded from this endpoint.');

        // Nothing resembling the PHP date artifact leaked under any other key.
        self::assertStringNotContainsString('timezone_type', $client->getResponse()->getContent());
    }

    public function testItKeepsTheFieldsTheAppRendersFrom(): void
    {
        $client = static::createClient();
        $this->seedSnapshot(self::getContainer()->get(EntityManagerInterface::class));

        $client->request('GET', '/api/telemetry');
        $item = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['feed'][0];

        self::assertSame('PYRAMID', $item['category']);
        self::assertSame('Dulwich Hamlet promoted to Tier 6', $item['text']);
        self::assertSame('£246,300', $item['amountExact']);
        self::assertSame('club-abc-123', $item['clubId'], 'clubId is the identifier; club name is not unique.');
        self::assertSame(['kit' => 'stripes', 'primary' => '#ff0000'], $item['clubBadge']);
        self::assertSame(['tierFrom' => 7, 'tierTo' => 6], $item['meta']);
        // The portable instant the client computes relative time from, replacing `time`.
        self::assertSame('2026-10-21T19:15:00+00:00', $item['timestampIso']);
    }

    public function testCountersAreTypedAndQualifiedForTheClient(): void
    {
        $client = static::createClient();
        $this->seedSnapshot(self::getContainer()->get(EntityManagerInterface::class));

        $client->request('GET', '/api/telemetry');
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        // bigint hydrates as a PHP string; the controller casts so this field's JSON type
        // matches every other counter rather than being quoted.
        self::assertIsInt($payload['stats']['capitalDeployedPence']);
        self::assertSame(24_630_000, $payload['stats']['capitalDeployedPence']);

        self::assertSame(['wins' => 7, 'draws' => 3, 'losses' => 2], $payload['stats']['results']);

        // weeksPlayed is sync-count x 4, so the payload says so in-band.
        self::assertTrue($payload['stats']['weeksPlayedApproximate']);

        // tableTurnover is counted over the 7-day feed window, not the 24h stats window.
        self::assertSame(7, $payload['stats']['tableTurnoverWindowDays']);
        self::assertSame(24, $payload['windows']['statsHours']);

        // null is distinct from 0 here: "not computable" vs "no net change".
        self::assertNull($payload['stats']['communityMoraleDelta']);
    }

    public function testItSupportsConditionalRequestsSoPollingClientsAreCheap(): void
    {
        $client = static::createClient();
        $this->seedSnapshot(self::getContainer()->get(EntityManagerInterface::class));

        $client->request('GET', '/api/telemetry');
        $etag = $client->getResponse()->getEtag();

        self::assertNotNull($etag, 'An ETag is required for the 15-min cadence to be pollable cheaply.');
        self::assertNotNull($client->getResponse()->getLastModified());

        $client->request('GET', '/api/telemetry', server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertSame(304, $client->getResponse()->getStatusCode());
    }

    public function testAZeroedSnapshotIsAValidResponseNotAnError(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);

        // Mirrors a brand-new environment where app:telemetry:generate has never run, and
        // the real steady state on a quiet day: the 24h window legitimately holds nothing.
        $existing = $em->getRepository(LiveTelemetrySnapshot::class)->findOneBy([]);
        if ($existing !== null) {
            $em->remove($existing);
            $em->flush();
        }

        $client->request('GET', '/api/telemetry');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([], $payload['feed']);
        self::assertSame(0, $payload['stats']['fixturesSimulated']);
        self::assertSame(0, $payload['stats']['capitalDeployedPence']);
    }

    /**
     * The landing page has to distinguish "nothing has happened" from "nothing has run".
     * Both produce a payload of zeros, and only the second is a problem to show an
     * operator — so the server says which it is rather than leaving the page to guess
     * from a row of noughts.
     */
    public function testAFreshlyGeneratedSnapshotIsNeitherStaleNorUngenerated(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedSnapshot($em);

        $client->request('GET', '/api/telemetry');
        $payload = json_decode($client->getResponse()->getContent(), true);

        $this->assertTrue($payload['generated']);
        $this->assertFalse($payload['stale']);
        $this->assertIsInt($payload['ageSeconds']);
        $this->assertLessThan(60, $payload['ageSeconds']);
    }

    /**
     * The exact failure this flag exists for: in October the generator was running out of
     * memory and the page served a 90-minute-old snapshot with nothing to say so.
     */
    public function testASnapshotOlderThanTwoCyclesIsReportedStale(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedSnapshot($em);

        $snapshot = $em->getRepository(LiveTelemetrySnapshot::class)->findOneBy([]);
        (new \ReflectionProperty(LiveTelemetrySnapshot::class, 'generatedAt'))
            ->setValue($snapshot, new \DateTimeImmutable('-91 minutes'));
        $em->flush();

        $client->request('GET', '/api/telemetry');
        $payload = json_decode($client->getResponse()->getContent(), true);

        $this->assertTrue($payload['generated'], 'it HAS been generated — just not recently');
        $this->assertTrue($payload['stale']);
        $this->assertGreaterThan(5400, $payload['ageSeconds']);
    }

    /** Every club a line names gets an entry, so the page can badge each one. */
    public function testFeedItemsCarryEveryClubTheyName(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedSnapshot($em);

        $client->request('GET', '/api/telemetry');
        $item = json_decode($client->getResponse()->getContent(), true)['feed'][0];

        $this->assertArrayHasKey('clubs', $item);
        $this->assertIsArray($item['clubs']);
    }
}
