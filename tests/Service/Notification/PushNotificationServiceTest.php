<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Message\SendPushNotificationMessage;
use App\Service\Notification\PushNotificationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class PushNotificationServiceTest extends TestCase
{
    private function envelope(object $message): Envelope
    {
        return new Envelope($message);
    }

    public function testNotifyUsersDispatchesTheExpectedMessageShape(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (SendPushNotificationMessage $message) {
                $this->assertSame(['user-1', 'user-2'], $message->userIds);
                $this->assertSame('Title', $message->title);
                $this->assertSame('Body', $message->body);
                $this->assertSame(['type' => 'ROUND_DRAWN', 'competitionId' => '123'], $message->data);

                return true;
            }))
            ->willReturn($this->envelope(new SendPushNotificationMessage([], '', '')));

        $service = new PushNotificationService($bus);
        $service->notifyUsers(['user-1', 'user-2'], 'Title', 'Body', ['type' => 'ROUND_DRAWN', 'competitionId' => 123]);
    }

    public function testNotifyUsersCastsDataValuesToStrings(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (SendPushNotificationMessage $message) {
                foreach ($message->data as $value) {
                    $this->assertIsString($value);
                }

                return true;
            }))
            ->willReturn($this->envelope(new SendPushNotificationMessage([], '', '')));

        $service = new PushNotificationService($bus);
        $service->notifyUsers(['user-1'], 'Title', 'Body', ['roundId' => 42, 'flag' => true]);
    }

    public function testNotifyUsersWithNoUserIdsDoesNotDispatch(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $service = new PushNotificationService($bus);
        $service->notifyUsers([], 'Title', 'Body');
    }
}
