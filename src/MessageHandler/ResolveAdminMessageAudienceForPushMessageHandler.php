<?php

namespace App\MessageHandler;

use App\Entity\AdminMessage;
use App\Enum\MessageTargetType;
use App\Message\ResolveAdminMessageAudienceForPushMessage;
use App\Repository\AdminMessageRepository;
use App\Repository\ClubRepository;
use App\Repository\UserDeviceRepository;
use App\Service\AdminMessageService;
use App\Service\Notification\PushNotificationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Eager, one-time audience resolution for an admin broadcast's push channel — the poll path's
 * AdminMessageService::findPendingForClub()/isEligible() are evaluated lazily per polling
 * club instead; this does the same isEligible() check but eagerly, across every club with a
 * registered device, since a push has no "poll" to hang eligibility off.
 */
#[AsMessageHandler]
class ResolveAdminMessageAudienceForPushMessageHandler
{
    /** Users per SendPushNotificationMessage dispatch — keeps any one message small. */
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly AdminMessageRepository $messageRepository,
        private readonly UserDeviceRepository $deviceRepository,
        private readonly ClubRepository $clubRepository,
        private readonly AdminMessageService $adminMessageService,
        private readonly PushNotificationService $pushNotificationService,
    ) {}

    /**
     * Returns the resolved recipient count — Messenger wraps a handler's return value in a
     * `HandledStamp`, which `NotificationLoggingSubscriber` reads to tell "resolved 0 recipients
     * and did nothing" apart from "actually dispatched pushes" in the admin's Notification Log,
     * since both look identical as a bare `WorkerMessageHandledEvent` otherwise.
     */
    public function __invoke(ResolveAdminMessageAudienceForPushMessage $message): int
    {
        $adminMessage = $this->messageRepository->find($message->adminMessageId);
        if ($adminMessage === null || !$adminMessage->isActive()) {
            return 0;
        }

        $userIds = $this->resolveEligibleUserIds($adminMessage);
        if ($userIds === []) {
            return 0;
        }

        $title = $adminMessage->getTitle();
        $body  = strip_tags($adminMessage->getBodyHtml());
        $data  = ['type' => 'ADMIN_MESSAGE', 'adminMessageId' => (string) $adminMessage->getId()];

        foreach (array_chunk($userIds, self::CHUNK_SIZE) as $chunk) {
            $this->pushNotificationService->notifyUsers($chunk, $title, $body, $data);
        }

        return count($userIds);
    }

    /** @return list<string> */
    private function resolveEligibleUserIds(AdminMessage $adminMessage): array
    {
        if ($adminMessage->getTargetType() === MessageTargetType::DIRECT_CLUB) {
            $targetClub = $adminMessage->getTargetClub();

            return $targetClub === null ? [] : [(string) $targetClub->getUser()->getId()];
        }

        $userIds = [];

        // Only clubs with at least one registered device can ever receive a push — bounds the
        // (potentially slow, per-club) eligibility evaluation below to just that pool instead
        // of every club in the game.
        foreach ($this->deviceRepository->findDistinctUsersWithDevice() as $user) {
            // A push targets the user's CURRENT club (most-recently-created — see
            // ClubRepository::findByUser()'s own docblock), same convention documented for
            // MessageDelivery: devices are User-scoped, but the cohort axes this message
            // targets (reputation/tier/etc.) only exist on Club.
            $club = $this->clubRepository->findByUser($user);
            if ($club === null) {
                continue;
            }

            if ($adminMessage->getTargetType() === MessageTargetType::BROADCAST
                || $this->adminMessageService->isEligible($adminMessage, $club)
            ) {
                $userIds[] = (string) $user->getId();
            }
        }

        return array_values(array_unique($userIds));
    }
}
