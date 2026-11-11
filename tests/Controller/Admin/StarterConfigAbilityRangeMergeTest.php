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
 * Saving the Starter config form must not delete a country's ability band.
 *
 * THE FORM CANNOT SHOW EVERY COUNTRY IT STORES. Its grid is built from countries that already
 * have League rows (DashboardController::starterConfig()), while league_ability_ranges is keyed
 * by country code with no such restriction — so a country without a pyramid is stored but never
 * rendered. The save then wrote `json_encode($ranges)` over the whole column, which deleted
 * every unrendered country's band without a word.
 *
 * That is not a hypothetical. The locations dashboard now has a button whose entire job is to
 * give a league-less country a band — precisely the row this save used to drop — so the two
 * features would have quietly undone each other. The symptom would have been a readiness
 * blocker reappearing with nothing on screen to explain it.
 *
 * Tested through the HTTP round trip because the merge lives in the save handler, not on the
 * entity, and the entity was never the risky part.
 */
class StarterConfigAbilityRangeMergeTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'starter-config-ranges-admin@example.com';

    /** A country the grid can never render: no League rows exist for it. */
    private const UNRENDERED = 'ZR';

    /** @var array<string, mixed>|null */
    private ?array $originalRanges = null;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        // The client first: touching the container boots the kernel, and WebTestCase refuses
        // to create a client afterwards.
        $this->client = static::createClient();
        $this->loginAsAdmin($this->client);

        $this->originalRanges = self::getContainer()
            ->get(StarterConfigRepository::class)->getConfig()->getLeagueAbilityRanges();
    }

    protected function tearDown(): void
    {
        // starter_config is a singleton row nothing purges.
        if ($this->originalRanges !== null) {
            self::getContainer()->get(Connection::class)->executeStatement(
                'UPDATE starter_config SET league_ability_ranges = :val',
                ['val' => json_encode($this->originalRanges)],
            );
            self::getContainer()->get(EntityManagerInterface::class)->clear();
        }

        parent::tearDown();
    }

    public function testACountryTheFormCannotShowKeepsItsBand(): void
    {
        $band = ['1' => ['min' => 70, 'max' => 90]];
        $this->storeRanges([self::UNRENDERED => $band] + $this->originalRanges);

        // A save that posts no leagueRanges at all — which is what every other starter-config
        // test does, and what a browser does when the grid is empty.
        $this->submit($this->client, []);

        $this->assertSame(
            $band,
            $this->storedRanges()[self::UNRENDERED] ?? null,
            'a country absent from the form must keep the band it already had',
        );
    }

    public function testAnEmptyGridDoesNotWipeEveryCountry(): void
    {
        // Seeded rather than read: the test database is built by schema:create and carries no
        // operator content, so the column can legitimately start empty — and "nothing was
        // lost" is not a claim worth making about nothing.
        $before = [
            'EN'             => ['1' => ['min' => 75, 'max' => 95]],
            'ES'             => ['1' => ['min' => 70, 'max' => 90]],
            self::UNRENDERED => ['1' => ['min' => 60, 'max' => 80]],
        ];
        $this->storeRanges($before);

        $this->submit($this->client, []);

        $this->assertSame($before, $this->storedRanges());
    }

    public function testACountryThatIsPostedIsStillReplaced(): void
    {
        // The merge must not turn the form read-only: a rendered country's whole tier map is
        // replaced by what was submitted, so removing a tier on screen still removes it.
        $this->storeRanges([
            'EN'             => ['1' => ['min' => 1, 'max' => 2], '2' => ['min' => 3, 'max' => 4]],
            self::UNRENDERED => ['1' => ['min' => 70, 'max' => 90]],
        ]);

        $this->submit($this->client, [
            'leagueRanges' => ['EN' => ['1' => ['min' => '50', 'max' => '60']]],
        ]);

        $stored = $this->storedRanges();

        $this->assertSame(['1' => ['min' => 50, 'max' => 60]], $stored['EN'] ?? null, 'posted wins, and is cast to int');
        $this->assertSame(['1' => ['min' => 70, 'max' => 90]], $stored[self::UNRENDERED] ?? null, 'unposted survives');
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @return array<string, mixed> */
    private function storedRanges(): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $raw = (string) self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT league_ability_ranges FROM starter_config');

        return json_decode($raw, true) ?? [];
    }

    /** @param array<string, mixed> $ranges */
    private function storeRanges(array $ranges): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE starter_config SET league_ability_ranges = :val',
            ['val' => json_encode($ranges)],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * Posts the starter-config form with $overrides merged over a minimal valid body.
     *
     * Same shape as StarterConfigEnrichmentCapTest::submit(): the handler reads every field
     * with its own default, so sending the few whose defaults would rewrite real values keeps
     * this from being a destructive test.
     *
     * @param array<string, mixed> $overrides
     */
    private function submit(KernelBrowser $client, array $overrides): void
    {
        $config = self::getContainer()->get(StarterConfigRepository::class)->getConfig();

        $client->request('POST', '/admin/starter-config/save', [
            '_token'                       => $this->seedValidCsrfToken($client, 'save_starter_config'),
            'startingBalance'              => $config->getStartingBalancePounds(),
            'userStartingBalanceMinPounds' => (int) round($config->getUserStartingBalanceMinPence() / 100),
            'userStartingBalanceMaxPounds' => (int) round($config->getUserStartingBalanceMaxPence() / 100),
            'starterPlayerCount'           => $config->getStarterPlayerCount(),
            'starterSponsorTier'           => $config->getStarterSponsorTier(),
            'starterClubTier'              => $config->getStarterClubTier(),
        ] + $overrides);

        $this->assertResponseRedirects();
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

    private function seedValidCsrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value   = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $session = $client->getContainer()->get('session.factory')->createSession();

        $existing = $client->getCookieJar()->get($session->getName());
        if ($existing !== null) {
            $session->setId($existing->getValue());
        }

        $session->start();
        $session->set("_csrf/{$tokenId}", $value);
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $value;
    }
}
