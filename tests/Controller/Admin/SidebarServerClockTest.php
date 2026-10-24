<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sidebar states the server's own time under the dashboard title.
 *
 * The server runs UTC and the operator usually does not, so every timestamp in
 * this panel — cron runs, deploy logs, sync records — otherwise needs a mental
 * conversion before it can be read against anything.
 *
 * This renders through EasyAdmin's real layout, so it also catches the thing
 * that makes `header_logo` worth a test at all: the block is overridden by
 * restating it, which means an upstream change to the anchor markup can leave
 * the override silently out of step.
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

    public function testTheSidebarRendersTheServerTimeUnderTheDashboardTitle(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $before  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $crawler = $client->request('GET', '/admin');
        $after   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->assertResponseIsSuccessful();

        $clock = $crawler->filter('.ea-sidebar-logo #bmc-server-clock');
        $this->assertCount(1, $clock, 'the clock must render inside the sidebar logo block');

        // Still inside the logo block, and still a sibling of the dashboard link
        // rather than inside it — clicking the clock must not navigate.
        $this->assertCount(1, $crawler->filter('.ea-sidebar-logo a.logo'));
        $this->assertCount(0, $crawler->filter('.ea-sidebar-logo a.logo #bmc-server-clock'));

        // The anchor the ticker reads has to be a real instant the browser can parse.
        $stamp  = $clock->attr('data-server-time');
        $parsed = new \DateTimeImmutable($stamp);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $parsed->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $parsed->getTimestamp());

        // Rendered value is the no-JS fallback and the first paint, so it must
        // already read as server time rather than waiting for the ticker.
        $value = trim($clock->filter('.bmc-server-clock-value')->text());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC$/', $value);
        $this->assertStringStartsWith($parsed->format('Y-m-d H:i'), $value);
    }

    /**
     * The dashboard title must survive the override — reproducing the anchor is
     * how this block is extended, so losing the title is the way it would break.
     */
    public function testTheDashboardTitleIsStillRendered(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler = $client->request('GET', '/admin');

        $logo = $crawler->filter('.ea-sidebar-logo a.logo .logo-custom');
        $this->assertCount(1, $logo);
        $this->assertStringContainsString('Build My Club', $logo->text());
        $this->assertCount(1, $logo->filter('img'), 'the logo image is part of the dashboard title');
    }

    /** The ticker is pinned to UTC on purpose; a local-time render defeats the point. */
    public function testTheTickerFormatsInUtcRatherThanTheViewersZone(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin');
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString("timeZone: 'UTC'", $html);
        $this->assertStringNotContainsString('toLocaleString()', $html);
    }
}
