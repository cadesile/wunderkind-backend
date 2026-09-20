<?php

namespace App\MessageHandler;

use App\Message\SendPushNotificationMessage;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resolves devices for the message's user ids fresh at handle time (not pre-resolved at
 * dispatch), then sends one multicast FCM call per chunk of ≤500 tokens (FCM's own limit).
 * A token FCM reports as unknown/invalid is deleted from user_device — this is the "stale
 * token" cleanup the client-registration flow relies on the server to do for it.
 *
 * Sets an explicit per-message collapse key (Android `collapse_key` / iOS
 * `apns-collapse-id`) derived from the full `data` payload — without one, FCM defaults to
 * collapsing on the app's own package/bundle id, meaning *every* push this app ever sends
 * shares one collapse slot regardless of type. A device offline when, say, a ROUND_DRAWN and
 * a later unrelated ADMIN_MESSAGE both queue up would then only ever receive the second one;
 * the first is dropped, not just delayed. Keying on the full data payload (not just `type`)
 * additionally keeps two *distinct* events of the same type — two different fixtures'
 * MATCH_RESULT pushes, say — from colliding with each other too.
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

        $collapseKey = $this->collapseKeyFor($message);

        $cloudMessage = CloudMessage::new()
            ->withNotification(Notification::create($message->title, $message->body))
            ->withData(array_map('strval', $message->data))
            ->withAndroidConfig(AndroidConfig::fromArray(['collapse_key' => $collapseKey]))
            ->withApnsConfig(ApnsConfig::fromArray(['headers' => ['apns-collapse-id' => $collapseKey]]));

        foreach (array_chunk(array_keys($devicesByToken), self::MAX_TOKENS_PER_CALL) as $tokenChunk) {
            $report = $this->messaging->sendMulticast($cloudMessage, $tokenChunk);

            foreach ([...$report->unknownTokens(), ...$report->invalidTokens()] as $staleToken) {
                $this->em->remove($devicesByToken[$staleToken]);
            }
        }

        $this->em->flush();
    }

    /**
     * Apple caps `apns-collapse-id` at 64 bytes, so this hashes rather than concatenating the
     * data payload's values directly (a MATCH_RESULT's three UUIDs alone would already exceed
     * that). The `type` prefix is purely for readability when eyeballing FCM diagnostics —
     * it's the hash that actually guarantees distinctness between different events.
     */
    private function collapseKeyFor(SendPushNotificationMessage $message): string
    {
        $data = $message->data;
        ksort($data);

        $type = $data['type'] ?? 'unknown';
        $hash = substr(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 0, 16);

        return sprintf('%s-%s', $type, $hash);
    }
}
