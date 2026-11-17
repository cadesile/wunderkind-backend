<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Service\WorldInitializationService;
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
        $conn = self::getContainer()->get(Connection::class);

        // Sets first — their payload rows follow via ON DELETE CASCADE, so deleting the cache
        // rows on their own would leave the sets behind and the stock figures on the screen
        // would carry across tests.
        $conn->executeStatement('DELETE FROM world_pack_set WHERE country = :c', ['c' => self::COUNTRY]);
        $conn->executeStatement(
            'DELETE FROM country_world_pack_cache WHERE country = :c',
            ['c' => self::COUNTRY],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->seededSets = [];
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

    /**
     * One club, two players — the counts findAllSummaries() digs out of the payload JSON.
     *
     * A payload row now belongs to a WorldPackSet, so the fixture has to make one. That is the
     * schema being honest rather than ceremony: a tier payload with no set is a row nothing
     * can serve, because a club is given a SET and reads its tiers out of that.
     */
    private function seedEntry(int $tier = 3): CountryWorldPackCache
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $set = new \App\Entity\WorldPack\WorldPackSet(
            self::COUNTRY,
            \App\Service\WorldInitializationService::WORLD_PACK_VERSION,
            ['players' => [['id' => 1]], 'staff' => [], 'scouts' => []],
            0,
            8,
        );
        $set->setStatus(\App\Enum\WorldPack\WorldPackSetStatus::READY);
        $set->setReadyAt(new \DateTimeImmutable());
        $em->persist($set);

        $entry = new CountryWorldPackCache($set, $tier, [
            'clubs' => [
                ['name' => 'Screen Test FC', 'players' => [['id' => 1], ['id' => 2]]],
            ],
        ]);

        $em->persist($entry);
        $em->flush();

        $this->seededSets[] = $set;

        return $entry;
    }

    /** @var \App\Entity\WorldPack\WorldPackSet[] */
    private array $seededSets = [];

    /** Stamps a seeded entry with a version, bypassing the constructor's current-version default. */
    private function stampVersion(CountryWorldPackCache $entry, int $version): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE country_world_pack_cache SET payload_version = :v WHERE id = :id',
            ['v' => $version, 'id' => $entry->getId()->toRfc4122()],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
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

    /**
     * Ticking a country has to LOOK like it did something.
     *
     * Reported from this screen: "the checkbox doesn't appear to fill or change colour". It
     * did not, and could not. `.bmc-tile` is painted by
     * `.bmc-check input:checked + .bmc-tile, .bmc-selbox input:checked + .bmc-tile` — both
     * scoped to a wrapper class this row does not carry, since its label is a
     * `.bmc-pick-row`. The third alternative, `.bmc-tile.is-on`, is set by the nationality
     * picker's JS but not by this page's, which only moves `aria-checked` on the row. So all
     * three ways to fill the tile missed, on every row, in both states.
     *
     * ASSERTED AGAINST THE SHIPPED STYLESHEET rather than the page, for the same reason
     * SidebarServerClockTest reads bmc-admin.js: the rule is in a file the HTML only links
     * to. A markup-only assertion would have passed throughout the bug.
     */
    public function testATickedCountryFillsItsCheckbox(): void
    {
        $crawler = $this->open();

        // display:none cannot take focus, so `hidden` here made the picker mouse-only — and
        // would make the focus-visible rule below unreachable.
        $box = $crawler->filter('#country-pick .bmc-pick-row input.country-checkbox')->first();
        self::assertNull($box->attr('hidden'), 'the checkbox must stay focusable');

        // COMMENTS STRIPPED FIRST. What is asserted below is the absence of a SELECTOR, and
        // the comment above that selector names the old one it replaced — so a raw search
        // finds the prose and fails on a stylesheet that is perfectly correct.
        $css = self::rules(\dirname(__DIR__, 3) . '/public/bmc-admin.css');

        // The fill rule must not require a wrapper class. `.bmc-tile` is ours and exists
        // nowhere we do not put it, so the whole rule is "a checked input fills the tile
        // next to it" — which is what makes it work in a .bmc-pick-row as well as a
        // .bmc-check.
        self::assertStringContainsString('input:checked + .bmc-tile', $css);
        self::assertStringNotContainsString('.bmc-check input:checked + .bmc-tile', $css);
        self::assertStringNotContainsString('.bmc-selbox input:checked + .bmc-tile', $css);

        // And the row itself has to read as picked: --wk-pressed alone is a few points of
        // hue off the picklist's own background, which is what made a picked row hard to
        // pick out at all.
        self::assertMatchesRegularExpression(
            '/\.bmc-pick-row\[aria-checked="true"\][^}]*border-left-color:\s*var\(--wk-accent\)/',
            self::rules(\dirname(__DIR__, 3) . '/public/bmc-pages.css'),
            'a picked row needs more than a background shift',
        );
    }

    /** A stylesheet with its /* … *\/ comments removed, so an assertion sees rules only. */
    private static function rules(string $path): string
    {
        $css = file_get_contents($path);
        self::assertNotFalse($css, $path . ' must be readable');

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    // ── Version and staleness, added 2026-11-15 ──────────────────────────

    public function testTheStatsStripShowsTheWorldpackVersion(): void
    {
        // The operator's question is "which generation is this box building?", and before this
        // the only answer was reading a PHP constant.
        $this->seedEntry();

        $text = $this->open()->filter('.bmc-stats')->text();

        self::assertStringContainsString('WORLDPACK VERSION', $text);
        self::assertStringContainsString('v' . WorldInitializationService::WORLD_PACK_VERSION, $text);
    }

    public function testAFreshPackCountsAsCurrentNotStale(): void
    {
        // NOTE the constructor defaults payloadVersion to 0, so a seeded entry is stale until
        // stamped — the real build path passes the version explicitly. Stamped here so the
        // test exercises "current" rather than accidentally re-testing "stale".
        $this->stampVersion($this->seedEntry(), WorldInitializationService::WORLD_PACK_VERSION);

        $text = $this->open()->filter('.bmc-stats')->text();

        self::assertMatchesRegularExpression('/STALE PACKS\s*0/', $text);
        self::assertStringContainsString('CURRENT', $this->open()->filter('#country-pick')->text());
    }

    public function testAnOldVersionedPackIsCountedAndLabelledStale(): void
    {
        // THE CASE THE FEATURE EXISTS FOR. A pack built before a calibration is
        // indistinguishable from one built after it unless something says so.
        $entry = $this->seedEntry();
        $this->stampVersion($entry, WorldInitializationService::WORLD_PACK_VERSION - 1);

        self::assertMatchesRegularExpression('/STALE PACKS\s*1/', $this->open()->filter('.bmc-stats')->text());
        self::assertStringContainsString('STALE 1/1', $this->open()->filter('#country-pick')->text());
    }

    public function testACountryWithNoPacksReadsNoneRatherThanCurrent(): void
    {
        // Three states, not two: "nothing cached" needs a first warm, "stale" needs a
        // regenerate. Collapsing them would tell an operator to regenerate a country that has
        // nothing to regenerate.
        $pick = $this->open()->filter('#country-pick')->text();

        self::assertStringContainsString('NONE', $pick);
        self::assertStringNotContainsString('STALE', $pick, 'an empty cache has nothing stale in it');
    }

}
