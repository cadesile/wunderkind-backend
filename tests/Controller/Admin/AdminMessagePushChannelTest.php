<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\AdminMessageCrudController;
use App\Entity\AdminMessage;
use App\Entity\AudienceGroup;
use App\Entity\Club;
use App\Entity\User;
use App\Enum\AudienceCriteriaType;
use App\Enum\MessageTargetType;
use App\Message\ResolveAdminMessageAudienceForPushMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * Exercises AdminMessageCrudController's push-dispatch gate (dispatchPushIfDue()) directly
 * against the controller service — persistEntity()/updateEntity() don't need a real HTTP
 * request for this logic, and going through EasyAdmin's actual form submission would only add
 * CSRF/form-field plumbing without exercising anything this test doesn't already cover.
 */
class AdminMessagePushChannelTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AdminMessageCrudController $controller;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->controller = self::getContainer()->get(AdminMessageCrudController::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE message_delivery, admin_message_audience_group, admin_message,
                      audience_group_member, audience_group, user_device CASCADE',
        );
    }

    private function transport(): mixed
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    /** @return list<ResolveAdminMessageAudienceForPushMessage> */
    private function sentResolveMessages(): array
    {
        return array_values(array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof ResolveAdminMessageAudienceForPushMessage));
    }

    private function createClubWithDevice(): Club
    {
        $user = new User('push-admin-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club('Push Admin FC', $user);

        $this->em->persist($user);
        $this->em->persist($club);
        $this->em->persist(new \App\Entity\UserDevice($user, 'token-' . uniqid('', true), \App\Enum\DevicePlatform::ANDROID));
        $this->em->flush();

        return $club;
    }

    public function testActiveMessageWithSendAsPushDispatchesResolutionOnce(): void
    {
        $message = new AdminMessage();
        $message->setTitle('Push me');
        $message->setBodyHtml('<p>Hello</p>');
        $message->setIsActive(true);
        $message->setSendAsPush(true);
        $message->setValidFrom(new \DateTimeImmutable('-1 hour'));

        $this->controller->persistEntity($this->em, $message);

        $sent = $this->sentResolveMessages();
        $this->assertCount(1, $sent);
        $this->assertSame((string) $message->getId(), $sent[0]->adminMessageId);
        $this->assertNotNull($message->getPushSentAt());

        // Re-saving (e.g. an unrelated edit) must not resend it.
        $this->transport()->reset();
        $this->controller->updateEntity($this->em, $message);
        $this->assertSame([], $this->sentResolveMessages());
    }

    public function testInactiveMessageWithSendAsPushDoesNotDispatch(): void
    {
        $message = new AdminMessage();
        $message->setTitle('Draft');
        $message->setBodyHtml('<p>Hello</p>');
        $message->setIsActive(false);
        $message->setSendAsPush(true);
        $message->setValidFrom(new \DateTimeImmutable('-1 hour'));

        $this->controller->persistEntity($this->em, $message);

        $this->assertSame([], $this->sentResolveMessages());
        $this->assertNull($message->getPushSentAt());
    }

    public function testMessageWithoutSendAsPushDoesNotDispatch(): void
    {
        $message = new AdminMessage();
        $message->setTitle('Poll only');
        $message->setBodyHtml('<p>Hello</p>');
        $message->setIsActive(true);
        $message->setSendAsPush(false);
        $message->setValidFrom(new \DateTimeImmutable('-1 hour'));

        $this->controller->persistEntity($this->em, $message);

        $this->assertSame([], $this->sentResolveMessages());
    }

    public function testEnablingPushOnAnEditLaterDispatchesItThen(): void
    {
        $message = new AdminMessage();
        $message->setTitle('Later push');
        $message->setBodyHtml('<p>Hello</p>');
        $message->setIsActive(true);
        $message->setSendAsPush(false);
        $message->setValidFrom(new \DateTimeImmutable('-1 hour'));

        $this->controller->persistEntity($this->em, $message);
        $this->assertSame([], $this->sentResolveMessages());

        $message->setSendAsPush(true);
        $this->controller->updateEntity($this->em, $message);

        $sent = $this->sentResolveMessages();
        $this->assertCount(1, $sent);
    }

    public function testResolveHandlerBroadcastsToEveryClubWithADevice(): void
    {
        $withDevice    = $this->createClubWithDevice();
        $withoutDevice = $this->createClubWithDevice();
        $this->em->getConnection()->executeStatement(
            'DELETE FROM user_device WHERE user_id = ?',
            [$withoutDevice->getUser()->getId()->toRfc4122()],
        );

        $message = new AdminMessage();
        $message->setTitle('Broadcast push');
        $message->setBodyHtml('<p>Hi <strong>everyone</strong></p>');
        $message->setTargetType(MessageTargetType::BROADCAST);
        $message->setIsActive(true);
        $message->setSendAsPush(true);
        $message->setValidFrom(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($message);
        $this->em->flush();

        $handler = self::getContainer()->get(\App\MessageHandler\ResolveAdminMessageAudienceForPushMessageHandler::class);
        $handler(new ResolveAdminMessageAudienceForPushMessage((string) $message->getId()));

        $sentPush = array_values(array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof \App\Message\SendPushNotificationMessage));

        $this->assertCount(1, $sentPush);
        $this->assertSame([(string) $withDevice->getUser()->getId()], $sentPush[0]->userIds);
        $this->assertSame('Hi everyone', $sentPush[0]->body, 'HTML must be stripped for the push body.');
    }
}
