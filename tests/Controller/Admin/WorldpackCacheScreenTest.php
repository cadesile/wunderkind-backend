<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\CountryWorldPackCache;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Worldpack cache screen after the rebuild.
 *
 * SEEDS A REAL ENTRY, because half this page only exists when there is one: the ALL ENTRIES
 * fold and its per-entry delete button are both inside `{% if entries is not empty %}`. A test
 * against an empty cache renders the page successfully and checks nothing that matters.
 *
 * THE CLIENT IS MADE IN setUp(), NOT PER TEST. Touching the container seeds through a booted
 * kernel, and WebTestCase refuses a createClient() after that — "the kernel should only be
 * booted once". So the client comes first and every fixture is written through its container.
 *
 * The run poller is not covered here — it needs a live generation run, and the routes it calls
 * have their own tests. What is covered is the markup it depends on existing.
 */
class WorldpackCacheScreenTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'worldpack-screen@example.com';
    private const COUNTRY     = 'EN';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purge();
        $this->loginAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM country_world_pack_cache WHERE country = :c',
            ['c' => self::COUNTRY],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function loginAsAdmin(): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $this->client->loginUser($admin, 'admin');
    }

    /** One club, two players — the counts findAllSummaries() digs out of the payload JSON. */
    private function seedEntry(int $tier = 3): CountryWorldPackCache
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $entry = new CountryWorldPackCache(self::COUNTRY, $tier, [
            'clubs' => [
                ['name' => 'Screen Test FC', 'players' => [['id' => 1], ['id' => 2]]],
            ],
        ]);

        $em->persist($entry);
        $em->flush();

        return $entry;
    }

    private function open(): Crawler
    {
        $crawler = $this->client->request('GET', '/admin', ['routeName' => 'admin_worldpack_cache']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function testThePageRendersInTheRedesignedShell(): void
    {
        $this->open();

        self::assertSelectorTextContains('.bmc-title', 'WORLDPACK CACHE');
        self::assertSelectorTextContains('.bmc-crumb', 'CONFIG');
        self::assertSelectorExists('.bmc-page');
        self::assertSelectorNotExists('.card-body', 'no Bootstrap cards should survive the rebuild');
        self::assertSelectorNotExists('.form-select', 'selects must use the .bmc-select component');
    }

    /**
     * BOTH FOLDS ARE NATIVE <details>, with no Bootstrap collapse left.
     *
     * data-bs-toggle="collapse" was the last Bootstrap JS dependency on a redesigned page, and
     * it needs a matching #id on each half — so a typo silently produces a panel that never
     * opens, which still renders and still passes a "page loads" test. <details> cannot fail
     * that way, and needs no ids at all.
     */
    public function testTheSecondaryTablesFoldWithoutBootstrap(): void
    {
        $this->seedEntry();
        $crawler = $this->open();

        self::assertCount(2, $crawler->filter('details.bmc-fold'), 'cache status and all entries');
        self::assertCount(2, $crawler->filter('details.bmc-fold > summary'));
        self::assertSame(0, $crawler->filter('[data-bs-toggle]')->count(), 'no Bootstrap collapse may remain');
    }

    /** The entries table and its delete button exist only when there is an entry to show. */
    public function testASeededEntryAppearsWithADeleteThatConfirms(): void
    {
        $entry   = $this->seedEntry(3);
        $crawler = $this->open();
        $id      = (string) $entry->getId();

        self::assertCount(
            1,
            $crawler->filter(\sprintf('form[action*="%s"]', $id)),
            'the entry must offer its own delete form',
        );
        self::assertCount(
            1,
            $crawler->filter(\sprintf('form[action*="%s"] button[data-bmc-dialog-title]', $id)),
            'deleting an entry must confirm through the PixelDialog',
        );
        self::assertStringNotContainsString(
            'onsubmit="return confirm',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * The counts are dug out of the payload JSON by SQL — json_array_length over
     * payload->'clubs', and a sum over each club's players — so they are worth pinning against
     * a seeded payload of a known shape.
     */
    public function testTheEntryReportsItsClubAndPlayerCounts(): void
    {
        $this->seedEntry(5);
        $crawler = $this->open();

        $cells = $crawler
            ->filter('details.bmc-fold table.bmc-minitable')->last()
            ->filter('tbody tr')->first()
            ->filter('td')
            ->each(static fn (Crawler $td): string => trim($td->text()));

        self::assertSame('T5', $cells[1], 'tier');
        self::assertSame('1', $cells[2], 'one club in the payload');
        self::assertSame('2', $cells[3], 'two players across that club');
    }

    /** The picker drives the regenerate button, so its rows and quick-picks must be there. */
    public function testTheCountryPickerRendersWithQuickPicks(): void
    {
        $crawler = $this->open();

        self::assertGreaterThan(0, $crawler->filter('#country-pick .bmc-pick-row input.country-checkbox')->count());
        self::assertCount(1, $crawler->filter('[data-pick="all"]'));
        self::assertCount(1, $crawler->filter('[data-pick="none"]'));
        self::assertCount(1, $crawler->filter('#regenerate-btn'));
    }
}
