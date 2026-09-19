<?php

namespace App\MessageHandler;

use App\Message\SendPushNotificationMessage;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resolves devices for the message's user ids fresh at handle time (not pre-resolved at
 * dispatch), then sends one multicast FCM call per chunk of ≤500 tokens (FCM's own limit).
 * A token FCM reports as unknown/invalid is deleted from user_device — this is the "stale
 * token" cleanup the client-registration flow relies on the server to do for it.
 */
#[AsMessageHandler]
class SendPushNotificationMessageHandler
{
    /** FCM's own per-multicast-call limit. */
    private const MAX_TOKENS_PER_CALL = 500;

    public function __construct(
        private readonly Messaging $messaging,
        private readonly UserDeviceRepository $deviceRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(SendPushNotificationMessage $message): void
    {
        $devices = $this->deviceRepository->findByUserIds($message->userIds);
        if ($devices === []) {
            return;
        }

        $devicesByToken = [];
        foreach ($devices as $device) {
            $devicesByToken[$device->getDeviceToken()] = $device;
        }

        $cloudMessage = CloudMessage::new()
            ->withNotification(Notification::create($message->title, $message->body))
            ->withData(array_map('strval', $message->data));

        foreach (array_chunk(array_keys($devicesByToken), self::MAX_TOKENS_PER_CALL) as $tokenChunk) {
            $report = $this->messaging->sendMulticast($cloudMessage, $tokenChunk);

            foreach ([...$report->unknownTokens(), ...$report->invalidTokens()] as $staleToken) {
                $this->em->remove($devicesByToken[$staleToken]);
            }
        }

        $this->em->flush();
    }
}
