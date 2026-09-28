<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\Competition\ActiveCompetitionStatus;
use App\Message\ResolveNewCompetitionAudienceForPushMessage;
use App\Repository\ClubRepository;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\UserDeviceRepository;
use App\Service\Competition\EligibilityEvaluator;
use App\Service\Notification\PushNotificationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Eager, one-time audience resolution for a newly-opened competition's push — mirrors
 * ResolveAdminMessageAudienceForPushMessageHandler exactly, substituting
 * EligibilityEvaluator::evaluate() (this template's fixed entry constraints) for
 * AdminMessageService::isEligible() (an arbitrary criteria bag). Only clubs that would actually
 * be allowed to register are notified — a club outside the template's tier/country/etc.
 * constraints never sees this push.
 */
#[AsMessageHandler]
class ResolveNewCompetitionAudienceForPushMessageHandler
{
    /** Users per SendPushNotificationMessage dispatch — keeps any one message small. */
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly ActiveCompetitionRepository $activeCompetitionRepository,
        private readonly UserDeviceRepository $deviceRepository,
        private readonly ClubRepository $clubRepository,
        private readonly EligibilityEvaluator $eligibilityEvaluator,
        private readonly PushNotificationService $pushNotificationService,
    ) {}

    /** @return int Resolved recipient count — see NotificationLoggingSubscriber, which surfaces this via HandledStamp. */
    public function __invoke(ResolveNewCompetitionAudienceForPushMessage $message): int
    {
        $activeCompetition = $this->activeCompetitionRepository->find($message->activeCompetitionId);
        // Still open by the time this runs? A near-instant capacity fill (or admin action)
        // could have already locked/cancelled it — nothing left to advertise.
        if ($activeCompetition === null || $activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
            return 0;
        }

        $template = $activeCompetition->getTemplate();
        $userIds  = [];

        foreach ($this->deviceRepository->findDistinctUsersWithDevice() as $user) {
            $club = $this->clubRepository->findByUser($user);
            if ($club === null) {
                continue;
            }

            if ($this->eligibilityEvaluator->evaluate($template, $club)->eligible) {
                $userIds[] = (string) $user->getId();
            }
        }

        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return 0;
        }

        foreach (array_chunk($userIds, self::CHUNK_SIZE) as $chunk) {
            $this->pushNotificationService->notifyUsers(
                $chunk,
                'New tournament open!',
                sprintf('%s just opened for registration.', $template->getName()),
                ['type' => 'NEW_COMPETITION_OPEN', 'competitionId' => (string) $activeCompetition->getId()],
            );
        }

        return count($userIds);
    }
}
