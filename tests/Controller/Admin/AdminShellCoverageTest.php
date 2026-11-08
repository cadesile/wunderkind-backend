<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every custom (non-CRUD) admin page is on the redesigned shell, and no Bootstrap reaches the
 * browser from any of them.
 *
 * WHY ONE TEST OVER ALL OF THEM. The pages were migrated a few at a time over many commits,
 * and each got its own assertions — but nothing stopped the next person adding a page that
 * extends @EasyAdmin/layout.html.twig directly, or pasting a `card-body` back in. This is the
 * standing guard: it enumerates the routes rather than scanning templates, so a page is only
 * covered if it actually renders.
 *
 * THE BOOTSTRAP LIST IS THE INTERESTING PART. `--bs-` catches a Bootstrap CSS variable leaking
 * into an inline style, and `data-bs-` catches the collapse/modal/tab JS hooks — both of which
 * survived several passes precisely because they do not look like markup.
 *
 * SCOPED TO THE PAGE BLOCK, not the whole response. The shell's own layout still emits some
 * fa-* icons and a Bootstrap delete modal on every admin page; that is pre-existing debt
 * outside these pages' control, and asserting over the whole document would fail on all of them
 * for a reason none of them caused.
 */
class AdminShellCoverageTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'admin-shell-coverage@example.com';

    /** route => expected .bmc-title text */
    private const PAGES = [
        'admin_locations_dashboard'  => 'LOCATIONS',
        'admin_location_enrichment'  => 'ENRICHMENT',
        'admin_location_config'      => 'CONFIGURATION',
        'admin_starter_config'       => 'STARTER CONFIG',
        'admin_starter_pack_check'   => 'STARTER PACK CHECK',
        'admin_game_config'          => 'GAME CONFIG',
        'admin_worldpack_cache'      => 'WORLDPACK CACHE',
        'admin_telemetry_config'     => 'TELEMETRY CONFIG',
        'admin_app_links'            => 'APP LINKS',
        'admin_logs'                 => 'LOGS',
        'admin_settings'             => 'SETTINGS',
        'admin_notification_debug'   => 'DEBUG TOOLS',
        'admin_social_connections'   => 'SOCIAL CONNECTIONS',
        'admin_import_export'        => 'IMPORT / EXPORT',
        'admin_npc_clubs_content'    => 'GENERATE',
        'admin_facilities_overview'  => 'FACILITY TEMPLATES',
        'admin_leagues_overview'     => 'LEAGUE OVERVIEW',
        'admin_player_pool_config'   => 'PLAYER POOL',
        'admin_staff_pool_config'    => 'STAFF POOL',
        'admin_investor_pool_config' => 'INVESTOR POOL',
    ];

    /** Markup and hooks that must not survive on a redesigned page. */
    private const BOOTSTRAP = [
        'card-body', 'form-control', 'form-select', 'col-md-', 'row g-',
        'btn btn-', 'badge bg-', 'fa fa-', 'text-muted', '--bs-', 'data-bs-',
        'accordion', 'input-group', 'alert alert-',
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

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

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function pages(): iterable
    {
        foreach (self::PAGES as $route => $title) {
            yield $route => [$route, $title];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testThePageIsOnTheRedesignedShell(string $route, string $title): void
    {
        $this->client->request('GET', '/admin', ['routeName' => $route]);

        self::assertResponseIsSuccessful("{$route} should render");
        self::assertSelectorExists('.bmc-page', "{$route} should extend bmc_page");
        self::assertSelectorTextContains('.bmc-title', $title);
        self::assertSelectorExists('main[data-bmc-page]', "{$route} needs a pin slug");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testThePageCarriesNoBootstrap(string $route, string $title): void
    {
        $this->client->request('GET', '/admin', ['routeName' => $route]);
        self::assertResponseIsSuccessful();

        $html  = (string) $this->client->getResponse()->getContent();
        $start = strpos($html, '<div class="bmc-page');
        self::assertNotFalse($start, "{$route} has no .bmc-page block");

        $end  = strpos($html, 'id="bmc-dialog"', $start);
        $page = substr($html, $start, ($end === false ? \strlen($html) : $end) - $start);

        foreach (self::BOOTSTRAP as $needle) {
            self::assertStringNotContainsString($needle, $page, "{$route} still carries '{$needle}'");
        }
    }

    /**
     * Every page's markup must actually nest.
     *
     * WHY A PARSER AND NOT A SELECTOR. Game config was rebuilt by transforming 2259 lines of
     * Bootstrap in place, and the transform rewrote 30 `<div class="card cfg-section">` openers
     * to `<section>` while leaving their `</div>` closers alone — plus two `<div
     * class="form-check">` to `<label>`. The page still rendered, still returned 200, still
     * passed every selector assertion in this file and every other, because a selector finds an
     * element whether or not its ancestors closed in order. What it actually did was escape the
     * shell: .bmc-main is 100vh with overflow hidden, so content that climbs out of it lands in
     * the page's own scroll and leaves dead space under the sidebar.
     *
     * assertSelectorExists cannot see that. A real parse can, and the whole admin is cheap to
     * parse, so all of it is checked rather than the one page that broke.
     *
     * SCRIPTS ARE STRIPPED FIRST, because a `'<div …>'` inside a JS string is not markup — the
     * Generate page builds its pool table in JS and would otherwise read as unbalanced.
     *
     * @param array{0: string, 1: string} $page
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testThePageMarkupNestsCorrectly(string $route, string $title): void
    {
        $this->client->request('GET', '/admin', ['routeName' => $route]);
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $html = preg_replace('#<script\b.*?</script>#is', '', $html) ?? $html;

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new \DOMDocument();
        $doc->loadHTML($html, \LIBXML_NOWARNING | \LIBXML_NOERROR);

        // Only mis-nesting, not the long tail of things libxml dislikes about valid HTML5
        // (unknown tags, bare ampersands, boolean attributes).
        $mismatched = array_values(array_filter(
            libxml_get_errors(),
            static fn (\LibXMLError $e): bool => str_contains($e->message, 'Opening and ending tag mismatch')
                || str_contains($e->message, 'Unexpected end tag'),
        ));

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertSame(
            [],
            array_map(static fn (\LibXMLError $e): string => trim($e->message) . ' (line ' . $e->line . ')', $mismatched),
            "{$route} has mis-nested markup",
        );
    }
}
