<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\User;
use App\Message\SendPushNotificationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class NotificationDebugControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'notification-debug-test-admin@example.com';

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

    private function createClub(EntityManagerInterface $em): Club
    {
        $user = new User('notification-debug-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club('Debug Test Club', $user);

        $em->persist($user);
        $em->persist($club);
        $em->flush();

        return $club;
    }

    public function testDebugPageLoadsForAnAdmin(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        // Must go through the dashboard's own /admin?routeName=... entry point — EasyAdmin only
        // populates the `ea` Twig context on that matched route, and this page's template
        // extends @EasyAdmin/layout.html.twig, which reads it. MenuItem::linkToRoute() (used in
        // DashboardController) already generates URLs this way; hitting the plain route directly
        // throws "Impossible to access an attribute (i18n) on a null variable".
        $client->request('GET', '/admin', ['routeName' => 'admin_notification_debug']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h5', 'Notification Debug Tools');
    }

    public function testTriggerActionDispatchesTheExpectedPushMessage(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $club = $this->createClub($em);

        $crawler   = $client->request('GET', '/admin', ['routeName' => 'admin_notification_debug']);
        $csrfToken = $crawler->filter('.notification-trigger-form[data-type="MATCH_RESULT"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', sprintf('/admin/notifications/debug/trigger/MATCH_RESULT/%s', $club->getId()), [
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseRedirects();

        // KernelBrowser reboots the kernel (and so the container) before every request, so the
        // in-memory transport must be fetched *after* the request that dispatched into it — a
        // reference grabbed beforehand belongs to an already-discarded container.
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages  = array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
        $matchResultMessages = array_filter($messages, static fn ($m) => $m instanceof SendPushNotificationMessage && ($m->data['type'] ?? null) === 'MATCH_RESULT');

        self::assertCount(1, $matchResultMessages);
        $message = array_values($matchResultMessages)[0];
        self::assertSame([(string) $club->getUser()->getId()], $message->userIds);
        self::assertStringContainsString('[TEST]', $message->title);
    }

    public function testTriggerActionRejectsAnInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $club = $this->createClub($em);

        $client->request('POST', sprintf('/admin/notifications/debug/trigger/MATCH_RESULT/%s', $club->getId()), [
            '_csrf_token' => 'not-a-real-token',
        ]);

        self::assertResponseRedirects();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent());
    }

    public function testForceProcessQueueActionRunsAgainstAnEmptyQueue(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler   = $client->request('GET', '/admin', ['routeName' => 'admin_notification_debug']);
        $csrfToken = $crawler->filter('form[action$="force-process-queue"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/notifications/debug/force-process-queue', [
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseRedirects();
    }

    /**
     * Regression test: `messenger:consume` run in-process (via
     * Symfony\Bundle\FrameworkBundle\Console\Application, sharing this request's own
     * container) logged the admin out — Messenger's Worker calls
     * services_resetter->reset() after each processed message, which resets
     * security.token_storage's authenticated token. force-process-queue must run
     * messenger:consume as a genuinely separate process instead, so it can't touch this
     * request's own security state.
     */
    public function testForceProcessQueueDoesNotLogTheAdminOut(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler   = $client->request('GET', '/admin', ['routeName' => 'admin_notification_debug']);
        $csrfToken = $crawler->filter('form[action$="force-process-queue"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/notifications/debug/force-process-queue', [
            '_csrf_token' => $csrfToken,
        ]);
        $client->followRedirect();

        // Any other authenticated admin page — if the token got wiped, this redirects to
        // /admin/login instead of rendering.
        self::assertResponseIsSuccessful();
    }
}
