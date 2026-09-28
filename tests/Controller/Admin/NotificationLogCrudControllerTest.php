<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\NotificationLog;
use App\Enum\NotificationLogStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Confirms the admin's "click a row to see the full payload" expectation is actually met:
 * EasyAdmin's own built-in row-click-to-detail behavior (`ea-clickable-row` +
 * `defaultActionUrl`, see vendor crud/index.html.twig) requires the DETAIL action to still be
 * enabled — this only disables NEW/EDIT/DELETE, so it should already work, but hadn't been
 * verified end-to-end until now.
 */
class NotificationLogCrudControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'notification-log-test-admin@example.com';

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

    public function testIndexRowIsClickableToADetailPageShowingTheFullPayload(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $log = new NotificationLog(
            NotificationLogStatus::SUCCESS,
            'SendPushNotificationMessage',
            'Push to 1 user(s): Full-time!',
            ['userIds' => ['user-1'], 'title' => 'Full-time!', 'body' => 'Home 1-0 Away', 'data' => ['type' => 'MATCH_RESULT', 'fixtureId' => 'fixture-1']],
        );
        $em->persist($log);
        $em->flush();

        $crawler = $client->request('GET', self::getContainer()->get('router')->generate('admin_notification_log_index'));
        self::assertResponseIsSuccessful();

        $row = $crawler->filter(sprintf('tr[data-id="%s"]', $log->getId()));
        self::assertGreaterThan(0, $row->count(), 'The row for this log entry should be present.');
        self::assertTrue($row->attr('class') !== null && str_contains($row->attr('class'), 'ea-clickable-row'), 'The row should be clickable (EasyAdmin\'s built-in row-click-to-detail).');

        $detailUrl = $row->attr('data-default-action-url');
        self::assertNotEmpty($detailUrl, 'The row should carry a default action URL to navigate to on click.');

        $client->request('GET', $detailUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'MATCH_RESULT');
        self::assertSelectorTextContains('body', 'fixture-1');
        self::assertSelectorTextContains('body', 'user-1', 'The detail page must show the full payload, including fields (like userIds) the index list column omits.');
    }
}
