<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Repository\StarterConfigRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The catchment share ladder's HTTP round trip: admin form → column → `GET /api/starter-config`.
 *
 * WHY THROUGH HTTP. The ladder is plumbed through four places that reference each other only by
 * string — a Twig input `name`, a `$request->request->all()` key, an entity setter, and a
 * payload key — and every one of those seams fails silently. The owner tunes these numbers; a
 * typo in any seam means the typed figure is discarded, the form redirects happily, and the
 * ladder keeps whatever it had. That is the same argument `StarterConfigEnrichmentCapTest`
 * makes for one scalar, made here for the array.
 *
 * ⚠️ **AND IT ASSERTS THE OLD KEY DID NOT MOVE.** `fanBaseRanges` is the app's fallback band
 * (`wunderkind-app/src/economy/fanBase.ts` takes the absolute path whenever the ladder or a
 * club's catchment is missing), in a unit 1,000x away from this one. A save that reached across
 * into it would publish percentages where headcounts belong.
 */
class StarterConfigFanBaseShareTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'starter-config-fanshare-admin@example.com';

    /** @var array<string, array{min: int, max: int}>|null */
    private ?array $originalLadder = null;
    /** @var array<string, array{min: int, max: int}>|null */
    private ?array $originalRanges = null;

    /**
     * starter_config is a SINGLETON ROW NOTHING PURGES between test files, so anything this
     * file writes would otherwise reach every later one. Captured on entry to each test,
     * restored in tearDown. Two other files in this suite already leave the sibling
     * `game_config` row dirty and are a documented baseline failure; this one does not join
     * them.
     *
     * Called AFTER createClient() rather than from setUp() on purpose: touching the container
     * boots the kernel, and Symfony refuses a createClient() that follows a boot.
     */
    private function rememberOriginals(): void
    {
        $row = self::getContainer()->get(Connection::class)
            ->fetchAssociative('SELECT fan_base_share_percent, fan_base_ranges FROM starter_config');

        if ($row !== false) {
            $this->originalLadder = json_decode((string) $row['fan_base_share_percent'], true);
            $this->originalRanges = json_decode((string) $row['fan_base_ranges'], true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalLadder !== null && $this->originalRanges !== null) {
            self::getContainer()->get(Connection::class)->executeStatement(
                'UPDATE starter_config SET fan_base_share_percent = :ladder, fan_base_ranges = :ranges',
                [
                    'ladder' => json_encode($this->originalLadder),
                    'ranges' => json_encode($this->originalRanges),
                ],
            );
        }

        parent::tearDown();
    }

    public function testEditingATiersShareSavesAndPublishesIt(): void
    {
        $client = static::createClient();
        $this->rememberOriginals();
        $this->loginAsAdmin($client);

        $this->submit($client, [
            'fanBaseSharePercent' => [
                1 => ['min' => 61, 'max' => 72],
                8 => ['min' => 14, 'max' => 19],
            ],
        ]);

        self::assertSame(
            ['1' => ['min' => 61, 'max' => 72], '8' => ['min' => 14, 'max' => 19]],
            $this->ladder(),
            'the typed share never reached the column',
        );

        $published = $this->publishedStarterConfig($client);

        self::assertSame(
            ['1' => ['min' => 61, 'max' => 72], '8' => ['min' => 14, 'max' => 19]],
            $published['fanBaseSharePercent'],
            'the saved ladder is not on GET /api/starter-config, so the app cannot read it',
        );
    }

    /**
     * 🔴 A share above 100% is not a share — it would give a club more supporters than its town
     * has inhabitants, and a negative one would floor every club at a single fan. Clamped rather
     * than refused, the same way the enrichment cap clamps a negative.
     */
    public function testBothBoundsAreClampedToZeroToOneHundredAndOrdered(): void
    {
        $client = static::createClient();
        $this->rememberOriginals();
        $this->loginAsAdmin($client);

        $this->submit($client, [
            'fanBaseSharePercent' => [
                1 => ['min' => 500, 'max' => 50000],
                2 => ['min' => -20, 'max' => 30],
                // Typed the wrong way round: accepted and ordered, not silently dropped.
                3 => ['min' => 40,  'max' => 20],
            ],
        ]);

        self::assertSame(
            [
                '1' => ['min' => 100, 'max' => 100],
                '2' => ['min' => 0,   'max' => 30],
                '3' => ['min' => 20,  'max' => 40],
            ],
            $this->ladder(),
        );
    }

    /**
     * The absolute band is a different key in a different unit, and a save of the ladder must
     * not touch it. This is the single most consequential assertion about this feature: the app
     * falls back to `fanBaseRanges` whenever the ladder or the catchment is absent, so a value
     * of 70 landing in it would not fail loudly — it would just quietly give every tier-1 club
     * 70 fans.
     */
    public function testSavingTheLadderLeavesTheAbsoluteFanBandAlone(): void
    {
        $client = static::createClient();
        $this->rememberOriginals();
        $this->loginAsAdmin($client);

        $before = $this->ranges();

        $this->submit($client, [
            'fanBaseSharePercent' => [1 => ['min' => 60, 'max' => 70]],
        ]);

        self::assertSame($before, $this->ranges(), 'fanBaseRanges moved on a share-ladder save');
        self::assertSame(
            $before,
            $this->publishedStarterConfig($client)['fanBaseRanges'],
            'fanBaseRanges must stay published unchanged — it is the app fallback',
        );
    }

    /**
     * An empty submission keeps whatever is stored, exactly as `fanBaseRanges` does. The reason
     * is the same: the handler serves one form of ~25 fields, and any page that posts a partial
     * body must not be able to wipe a ladder it did not render.
     */
    public function testAnAbsentLadderKeepsTheStoredOne(): void
    {
        $client = static::createClient();
        $this->rememberOriginals();
        $this->loginAsAdmin($client);

        $this->setLadder(['1' => ['min' => 60, 'max' => 70]]);
        $this->submit($client, []);

        self::assertSame(['1' => ['min' => 60, 'max' => 70]], $this->ladder());
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function submit(KernelBrowser $client, array $overrides): void
    {
        $config = self::getContainer()->get(StarterConfigRepository::class)->getConfig();
        $token  = $this->seedValidCsrfToken($client, 'save_starter_config');

        $client->request('POST', '/admin/starter-config/save', [
            '_token'                       => $token,
            'userStartingBalanceMinPounds' => (int) round($config->getUserStartingBalanceMinPence() / 100),
            'userStartingBalanceMaxPounds' => (int) round($config->getUserStartingBalanceMaxPence() / 100),
            'starterPlayerCount'           => $config->getStarterPlayerCount(),
            'starterSponsorTier'           => $config->getStarterSponsorTier(),
            'starterClubTier'              => $config->getStarterClubTier(),
        ] + $overrides);

        self::assertResponseRedirects();
    }

    /** @return array<string, array{min: int, max: int}> */
    private function ladder(): array
    {
        return json_decode((string) self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT fan_base_share_percent FROM starter_config'), true);
    }

    /** @return array<string, array{min: int, max: int}> */
    private function ranges(): array
    {
        return json_decode((string) self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT fan_base_ranges FROM starter_config'), true);
    }

    /** @param array<string, array{min: int, max: int}> $ladder */
    private function setLadder(array $ladder): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE starter_config SET fan_base_share_percent = :v',
            ['v' => json_encode($ladder)],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** @return array<string, mixed> */
    private function publishedStarterConfig(KernelBrowser $client): array
    {
        $client->request('GET', '/api/starter-config');
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    /** @param array<string, mixed> $values */
    private function seedSession(KernelBrowser $client, array $values): void
    {
        $session  = $client->getContainer()->get('session.factory')->createSession();
        $existing = $client->getCookieJar()->get($session->getName());

        if ($existing !== null) {
            $session->setId($existing->getValue());
        }
        $session->start();

        foreach ($values as $key => $value) {
            $session->set($key, $value);
        }
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function seedValidCsrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $this->seedSession($client, ["_csrf/{$tokenId}" => $value]);

        return $value;
    }
}
