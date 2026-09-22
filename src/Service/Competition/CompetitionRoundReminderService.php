<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Competition\CompetitionRound;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sends a "results incoming" push to a drawn round's actual participants — the entrants
 * with a fixture seeded into that round — a fixed lead time before its matchesResolveAt.
 * Only DRAWN rounds are eligible (a DRAW_PENDING round has no fixtures yet to remind
 * anyone about). Separate from CompetitionResultsService deliberately: this only ever
 * reads/reminds, never resolves or advances anything, so a reminder bug can't threaten
 * bracket-processing correctness.
 */
class CompetitionRoundReminderService
{
    /** How far ahead of a round's matchesResolveAt to send its reminder. */
    private const REMINDER_LEAD_MINUTES = 15;

    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly PushNotificationService $pushNotificationService,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return int Number of rounds this call actually sent a reminder for. */
    public function sendDueReminders(\DateTimeImmutable $now): int
    {
        $windowEnd = $now->modify(sprintf('+%d minutes', self::REMINDER_LEAD_MINUTES));
        $sent      = 0;

        foreach ($this->roundRepository->findDueForReminder($now, $windowEnd) as $round) {
            if ($this->claimReminder($round, $now)) {
                $this->sendReminder($round);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Claim-lock: an atomic conditional UPDATE, not ORM flush+catch — same idiom as
     * CompetitionDrawService::claimDraw()/CompetitionResultsService::claimResults(),
     * guarding against an overlapping cron tick sending the same round's reminder twice.
     */
    private function claimReminder(CompetitionRound $round, \DateTimeImmutable $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE competition_round SET reminder_sent_at = :now WHERE id = :id AND reminder_sent_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s'), 'id' => $round->getId()->toRfc4122()],
        );

        if ($affected > 0) {
            $this->em->refresh($round);

            return true;
        }

        return false;
    }

    private function sendReminder(CompetitionRound $round): void
    {
        $userIds = [];
        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round) as $fixture) {
            foreach ([$fixture->getHomeEntrant(), $fixture->getAwayEntrant()] as $entrant) {
                if ($entrant !== null) {
                    $userIds[] = (string) $entrant->getClub()->getUser()->getId();
                }
            }
        }
        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return;
        }

        $this->pushNotificationService->notifyUsers(
            $userIds,
            'Results incoming!',
            sprintf('%s results land in about %d minutes.', $round->getLabel(), self::REMINDER_LEAD_MINUTES),
            [
                'type'          => 'ROUND_RESOLVING_SOON',
                'competitionId' => (string) $round->getActiveCompetition()->getId(),
                'roundId'       => (string) $round->getId(),
            ],
        );
    }
}
