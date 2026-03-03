<?php

namespace App\Service;

use App\Dto\SyncRequest;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Enum\PlayerStatus;
use App\Repository\AcademyRepository;
use App\Repository\LeaderboardEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

class SyncService
{
    public function __construct(
        private readonly AcademyRepository          $academyRepository,
        private readonly LeaderboardEntryRepository $leaderboardEntryRepository,
        private readonly EntityManagerInterface     $em,
        private readonly EconomicService            $economicService,
        private readonly InboxService               $inboxService,
    ) {}

    /**
     * @return array{accepted: bool, weekNumber?: int, syncedAt?: string, academy?: array, reason?: string, currentWeek?: int}
     */
    public function process(User $user, SyncRequest $request): array
    {
        $academy = $this->academyRepository->findByUser($user);
        if ($academy === null) {
            throw new \RuntimeException('Academy not found for user.');
        }

        $clientTimestamp = new \DateTimeImmutable($request->clientTimestamp);

        $syncRecord = new SyncRecord(
            $academy,
            $request->weekNumber,
            $clientTimestamp,
            [
                'earningsDelta'   => $request->earningsDelta,
                'reputationDelta' => $request->reputationDelta,
                'hallOfFamePoints' => $request->hallOfFamePoints,
                'transfers'       => $request->transfers,
            ],
        );
        $this->em->persist($syncRecord);

        // Anti-cheat: reject week rollbacks
        if ($request->weekNumber < $academy->getLastSyncedWeek()) {
            $syncRecord->markInvalid('week_rollback');
            $this->em->flush();

            return [
                'accepted'    => false,
                'reason'      => 'week_rollback',
                'currentWeek' => $academy->getLastSyncedWeek(),
            ];
        }

        // Update Academy aggregate state
        $academy->setTotalCareerEarnings((int) round($academy->getTotalCareerEarnings() + $request->earningsDelta));
        $academy->setReputation(max(0, (int) round($academy->getReputation() + $request->reputationDelta)));
        $academy->setHallOfFamePoints(max($academy->getHallOfFamePoints(), (int) round($request->hallOfFamePoints)));
        $academy->setLastSyncedWeek($request->weekNumber);
        $academy->setLastSyncedAt(new \DateTimeImmutable());

        // Add earnings delta to liquid balance
        $academy->addFunds((int) round($request->earningsDelta));

        // Process sponsor payments (monthly, based on contract)
        $this->processSponsorPayments($academy, $clientTimestamp);

        // Deduct weekly staff and player salaries
        $this->deductWeeklySalaries($academy);

        // Upsert leaderboard entries for all-time and current ISO week
        $isoWeek = (new \DateTimeImmutable())->format('o-\WW');

        foreach ([LeaderboardCategory::CAREER_EARNINGS, LeaderboardCategory::ACADEMY_REPUTATION, LeaderboardCategory::HALL_OF_FAME] as $category) {
            $score = match ($category) {
                LeaderboardCategory::CAREER_EARNINGS    => $academy->getTotalCareerEarnings(),
                LeaderboardCategory::ACADEMY_REPUTATION => $academy->getReputation(),
                LeaderboardCategory::HALL_OF_FAME       => $academy->getHallOfFamePoints(),
            };

            foreach (['all-time', $isoWeek] as $period) {
                $entry = $this->leaderboardEntryRepository->findOrCreate($academy, $category, $period);
                $entry->setScore($score);
            }
        }

        // Financial year-end processing
        if ($academy->isFinancialYearEnd($request->weekNumber)) {
            $this->economicService->processFinancialYearEnd($academy);
        }

        // Sponsor contract health check
        $this->economicService->checkSponsorContracts($academy, $academy->getReputation());

        // Age-out checks
        $this->economicService->checkAgeOutPlayers($academy, $request->weekNumber, $clientTimestamp);

        $this->em->flush();

        $syncedAt = $academy->getLastSyncedAt();

        return [
            'accepted'   => true,
            'weekNumber' => $request->weekNumber,
            'syncedAt'   => $syncedAt->format(\DateTimeInterface::ATOM),
            'academy'    => [
                'reputation'          => $academy->getReputation(),
                'totalCareerEarnings' => $academy->getTotalCareerEarnings(),
                'hallOfFamePoints'    => $academy->getHallOfFamePoints(),
                'balance'             => $academy->getBalance(),
                'hasDebt'             => $academy->hasDebt(),
            ],
        ];
    }

    private function deductWeeklySalaries(Academy $academy): void
    {
        $total = 0;

        foreach ($academy->getStaff() as $staff) {
            $total += $staff->getWeeklySalary();
        }

        foreach ($academy->getPlayers() as $player) {
            if ($player->getStatus() === PlayerStatus::ACTIVE) {
                $total += $player->getContractValue();
            }
        }

        $academy->addFunds(-$total);
    }

    private function processSponsorPayments(Academy $academy, \DateTimeImmutable $now): void
    {
        foreach ($academy->getActiveSponsors() as $sponsor) {
            if ($sponsor->isPaymentDue($now)) {
                $academy->addFunds($sponsor->getMonthlyPayment());
                $sponsor->setLastPaymentAt($now);
            }
        }
    }
}
