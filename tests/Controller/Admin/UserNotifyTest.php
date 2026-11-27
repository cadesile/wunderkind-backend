<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\AdminMessage;
use App\Entity\Club;
use App\Entity\User;
use App\Enum\MessageDisplayType;
use App\Enum\MessageTargetType;
use App\Message\SendPushNotificationMessage;
use App\Repository\AdminMessageRepository;
use App\Service\Notification\OwnerNoticeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The Notify form on /admin/user/{id} (UserNotifyController + OwnerNoticeService).
 */
class UserNotifyTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'notify-test-admin@example.com';

    /** @var object[] */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(AdminMessage::class)->findBy(['targetType' => MessageTargetType::DIRECT_USER]) as $m) {
            $em->remove($m);
        }
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $em->remove($managed);
            }
        }
        $this->cleanup = [];
        $em->flush();
        parent::tearDown();
    }

    private function login(KernelBrowser $client): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('x');
            $em->persist($admin);
            $em->flush();
        }
        $client->loginUser($admin, 'admin');
    }

    private function owner(): User
    {
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(bin2hex(random_bytes(6)) . '@notify.test');
        $user->setPassword('x');
        $user->setName('Ada Owner');
        $em->persist($user);
        $em->flush();
        $this->cleanup[] = $user;

        return $user;
    }

    /** @param array<string, string> $fields */
    private function post(KernelBrowser $client, User $user, array $fields): void
    {
        $crawler = $client->request('GET', '/admin/user/' . $user->getId());
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('form[data-notify-form] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/users/' . $user->getId() . '/notify', $fields + [
            '_token'       => $token,
            'subject'      => 'Hello',
            'body'         => "Chairman Ada,\n\n* Claim Your Ground: pick a town.",
            'push_body'    => 'Open the app',
            'display_type' => 'modal_blocking',
        ]);
    }

    public function testThePageShowsTheFormPrefilledFromTheAccount(): void
    {
        $client = static::createClient();
        $this->login($client);
        $user = $this->owner();

        $crawler = $client->request('GET', '/admin/user/' . $user->getId());

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('#notify-body')->text();
        $this->assertStringContainsString('Chairman Ada Owner,', $body);
        $this->assertStringContainsString('Balance: £0.00', $body);
        // No club sale yet, so that placeholder stays for the operator to fill.
        $this->assertStringContainsString('[LIST_CLUB_SALE_LEDGER_ITEMS]', $body);
        $this->assertSame('New Era: Land Ownership & Chairman Compensation', $crawler->filter('#notify-subject')->attr('value'));
    }

    public function testTestModeOnlyEmailsTheAdminAndTouchesNoDevice(): void
    {
        $client = static::createClient();
        $client->enableProfiler();
        $this->login($client);
        $user = $this->owner();

        $this->post($client, $user, ['mode' => 'test', 'send_email' => '1', 'send_device' => '1', 'send_push' => '1']);

        $this->assertResponseRedirects();
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'To', self::TEST_ADMIN_EMAIL);
        $this->assertEmailHeaderSame($email, 'Subject', '[TEST] Hello');
        $this->assertSame([], $this->noticesFor($user));
    }

    public function testARealSendEmailsMessagesAndPushesTheOwner(): void
    {
        $client = static::createClient();
        $client->enableProfiler();
        $this->login($client);
        $user = $this->owner();

        $this->post($client, $user, ['mode' => 'send', 'send_email' => '1', 'send_device' => '1', 'send_push' => '1']);

        $this->assertResponseRedirects();
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'To', $user->getEmail());
        $this->assertEmailHtmlBodyContains($email, '<strong>Claim Your Ground:</strong> pick a town.');

        $notices = $this->noticesFor($user);
        $this->assertCount(1, $notices);
        $this->assertSame(MessageDisplayType::MODAL_BLOCKING, $notices[0]->getDisplayType());
        $this->assertNotNull($notices[0]->getPushSentAt());
        $this->assertStringContainsString('<li><strong>Claim Your Ground:</strong> pick a town.</li>', $notices[0]->getBodyHtml());

        /** @var InMemoryTransport $async */
        $async  = self::getContainer()->get('messenger.transport.async');
        $pushes = array_filter(
            array_map(static fn ($e) => $e->getMessage(), $async->getSent()),
            static fn ($m) => $m instanceof SendPushNotificationMessage,
        );
        $this->assertCount(1, $pushes);
        $push = array_values($pushes)[0];
        $this->assertSame([(string) $user->getId()], $push->userIds);
        $this->assertSame('Open the app', $push->body);
        $this->assertSame('ADMIN_MESSAGE', $push->data['type']);
    }

    public function testAnUnfilledPlaceholderBlocksARealSend(): void
    {
        $client = static::createClient();
        $client->enableProfiler();
        $this->login($client);
        $user = $this->owner();

        $this->post($client, $user, [
            'mode'       => 'send',
            'send_email' => '1',
            'body'       => 'We have deposited [LIST_CLUB_SALE_LEDGER_ITEMS].',
        ]);

        $this->assertResponseRedirects();
        $this->assertEmailCount(0);
        $this->assertSame([], $this->noticesFor($user));
    }

    public function testADirectUserMessageReachesWhicheverClubTheOwnerPlaysLater(): void
    {
        $container = self::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $user      = $this->owner();

        $container->get(OwnerNoticeService::class)->sendDeviceMessage(
            $user, 'Hello', 'Body', MessageDisplayType::INBOX_ITEM, false, '', null,
        );

        // The club is created AFTER the notice, as it will be after go-live.
        $club = new Club('Notify Later FC ' . bin2hex(random_bytes(3)), $user);
        $em->persist($club);
        $em->flush();
        $this->cleanup[] = $club;

        $other = new Club('Someone Else FC ' . bin2hex(random_bytes(3)), $this->owner());
        $em->persist($other);
        $em->flush();
        $this->cleanup[] = $other;

        $repo = $container->get(AdminMessageRepository::class);
        $this->assertSame(['Hello'], array_map(static fn ($m) => $m->getTitle(), array_filter(
            $repo->findCandidatesForClub($club),
            static fn ($m) => $m->getTargetType() === MessageTargetType::DIRECT_USER,
        )));
        $this->assertSame([], array_values(array_filter(
            $repo->findCandidatesForClub($other),
            static fn ($m) => $m->getTargetType() === MessageTargetType::DIRECT_USER,
        )));
    }

    /** @return list<AdminMessage> */
    private function noticesFor(User $user): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(AdminMessage::class)->findBy(['targetUser' => $user->getId()]);
    }
}
