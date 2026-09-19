<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\User;
use App\Entity\UserDevice;
use App\Enum\DevicePlatform;
use App\Message\SendPushNotificationMessage;
use App\MessageHandler\SendPushNotificationMessageHandler;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use PHPUnit\Framework\TestCase;

class SendPushNotificationMessageHandlerTest extends TestCase
{
    private function makeDevice(string $token): UserDevice
    {
        $user = new User("device-{$token}@example.com");

        return new UserDevice($user, $token, DevicePlatform::ANDROID);
    }

    public function testSendsAMulticastAndRemovesStaleTokens(): void
    {
        $goodDevice    = $this->makeDevice('good-token');
        $unknownDevice = $this->makeDevice('unknown-token');
        $invalidDevice = $this->makeDevice('invalid-token');

        $deviceRepository = $this->createMock(UserDeviceRepository::class);
        $deviceRepository->method('findByUserIds')->willReturn([$goodDevice, $unknownDevice, $invalidDevice]);

        $report = MulticastSendReport::withItems([
            SendReport::success(MessageTarget::with('token', 'good-token'), []),
            SendReport::failure(MessageTarget::with('token', 'unknown-token'), new NotFound('Requested entity was not found.')),
            SendReport::failure(MessageTarget::with('token', 'invalid-token'), new InvalidArgument('The registration token is not a valid FCM registration token.')),
        ]);

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('sendMulticast')
            ->with($this->isInstanceOf(CloudMessage::class), ['good-token', 'unknown-token', 'invalid-token'])
            ->willReturn($report);

        $em = $this->createMock(EntityManagerInterface::class);
        $removed = [];
        $em->expects($this->exactly(2))->method('remove')->willReturnCallback(function ($entity) use (&$removed) {
            $removed[] = $entity;
        });
        $em->expects($this->once())->method('flush');

        $handler = new SendPushNotificationMessageHandler($messaging, $deviceRepository, $em);
        $handler(new SendPushNotificationMessage(['user-1'], 'Title', 'Body'));

        $this->assertContains($unknownDevice, $removed);
        $this->assertContains($invalidDevice, $removed);
        $this->assertNotContains($goodDevice, $removed);
    }

    public function testNoDevicesMeansNoFirebaseCall(): void
    {
        $deviceRepository = $this->createMock(UserDeviceRepository::class);
        $deviceRepository->method('findByUserIds')->willReturn([]);

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->never())->method('sendMulticast');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $handler = new SendPushNotificationMessageHandler($messaging, $deviceRepository, $em);
        $handler(new SendPushNotificationMessage(['user-1'], 'Title', 'Body'));
    }

    public function testChunksTokensAtFiveHundredPerMulticastCall(): void
    {
        $devices = [];
        for ($i = 0; $i < 501; $i++) {
            $devices[] = $this->makeDevice("token-{$i}");
        }

        $deviceRepository = $this->createMock(UserDeviceRepository::class);
        $deviceRepository->method('findByUserIds')->willReturn($devices);

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->exactly(2))
            ->method('sendMulticast')
            ->willReturnCallback(fn ($message, array $tokens) => MulticastSendReport::withItems(
                array_map(static fn (string $t) => SendReport::success(MessageTarget::with('token', $t), []), $tokens),
            ));

        $em = $this->createMock(EntityManagerInterface::class);

        $handler = new SendPushNotificationMessageHandler($messaging, $deviceRepository, $em);
        $handler(new SendPushNotificationMessage(['user-1'], 'Title', 'Body'));
    }
}
