<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sidebar states the server's own time under the brand.
 *
 * The server runs UTC and the operator usually does not, so every timestamp in this panel —
 * cron runs, deploy logs, sync records — otherwise needs a mental conversion before it can be
 * read against anything.
 *
 * REWRITTEN FOR THE REDESIGNED SHELL, and the distinction matters. All three cases here went
 * red when the admin shell landed, because each of them named EasyAdmin's STOCK sidebar
 * markup: .ea-sidebar-logo, a.logo, .logo-custom, .bmc-server-clock-value. None of that
 * exists any more — the sidebar is .bmc-side and the clock is .bmc-clock. The CLOCK ITSELF was
 * carried over faithfully: it still renders server time, still carries the data-server-time
 * anchor, still says UTC, and is still a sibling of the brand link rather than inside it.
 *
 * So these assertions are re-pointed rather than relaxed. What each one protects is unchanged.
 */
class SidebarServerClockTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'sidebar-clock-test-admin@example.com';

    /** @see SocialAuthControllerTest for why the Admin must really exist. */
    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    public function testTheSidebarRendersTheServerTimeUnderTheBrand(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $before  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $crawler = $client->request('GET', '/admin');
        $after   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->assertResponseIsSuccessful();

        $clock = $crawler->filter('.bmc-side__head #bmc-server-clock');
        $this->assertCount(1, $clock, 'the clock must render inside the sidebar head');

        // Still a SIBLING of the brand link rather than inside it — clicking the clock must
        // not navigate. That was the original point of this pair and it still is.
        $this->assertCount(1, $crawler->filter('.bmc-side__head a.bmc-brand'));
        $this->assertCount(0, $crawler->filter('.bmc-side__head a.bmc-brand #bmc-server-clock'));

        // The anchor the ticker reads has to be a real instant the browser can parse.
        $stamp  = $clock->attr('data-server-time');
        $parsed = new \DateTimeImmutable($stamp);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $parsed->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $parsed->getTimestamp());

        // The rendered value is the no-JS fallback and the first paint, so it must already
        // read as server time rather than waiting for the ticker.
        $value = trim($clock->filter('.bmc-clock__value')->text());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value);
        $this->assertStringStartsWith($parsed->format('Y-m-d H:i'), $value);

        // The zone is stated on the label rather than appended to the value, so it must
        // actually be there — a bare timestamp with no zone is worse than none.
        $this->assertStringContainsString(
            'UTC',
            $clock->filter('.bmc-clock__label')->text(),
            'the clock must say which zone it is in',
        );
    }

    /**
     * The brand must survive. header_logo is extended by RESTATING it, so losing the title is
     * exactly how this block breaks — which is what made it worth a test in the first place.
     */
    public function testTheBrandIsStillRendered(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler = $client->request('GET', '/admin');

        $brand = $crawler->filter('.bmc-side__head a.bmc-brand');
        $this->assertCount(1, $brand);
        $this->assertStringContainsString('BUILD MY CLUB', $brand->filter('.bmc-brand__name')->text());
        $this->assertCount(1, $brand->filter('img'), 'the logo image is part of the brand');
    }

    /**
     * The ticker is pinned to UTC on purpose; a local-time render defeats the point.
     *
     * READ OFF THE SHIPPED ASSET, not the page. This used to assert "timeZone: 'UTC'" appeared
     * in the HTML, because the ticker was an inline <script>. It moved into bmc-admin.js (§7)
     * during the redesign, so that assertion was checking for a string in a file the page no
     * longer contains — it would have stayed red forever while the behaviour was perfectly
     * fine. The page is still asserted to LOAD the asset, so the two halves cannot drift.
     */
    public function testTheTickerFormatsInUtcRatherThanTheViewersZone(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin');
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('bmc-admin.js', $html, 'the page must load the ticker');

        $js = file_get_contents(\dirname(__DIR__, 3) . '/public/bmc-admin.js');
        self::assertNotFalse($js);

        $this->assertStringContainsString('getUTCHours', $js, 'the ticker must read UTC parts');
        $this->assertStringNotContainsString('toLocaleString()', $js);
        $this->assertStringNotContainsString('getHours()', $js, 'local-time getters defeat the point');
    }
}
