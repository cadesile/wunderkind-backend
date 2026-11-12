<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\GameConfig;
use App\Enum\SponsorStatus;
use App\Repository\GameConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class EconomicService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboxService           $inboxService,
        private readonly GameConfigRepository   $gameConfigRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Financial year-end processing
    // -------------------------------------------------------------------------

    /**
     * @return array{dividendPaidPence: int}
     */
    public function processFinancialYearEnd(Club $club): array
    {
        $annualProfit = $club->calculateAnnualProfit();
        $now          = new \DateTimeImmutable();

        foreach ($club->getInvestors() as $investor) {
            if (!$investor->isActive()) {
                continue;
            }

            $payout = $investor->calculateAnnualPayout($annualProfit);
            $investor->setLastPayoutAt($now);
            $club->addFunds(-$payout);

            $this->inboxService->sendSystemNotification(
                $club,
                "Annual investor payout: {$investor->getCompany()}",
                "Annual profit-sharing payout of £" . number_format($payout / 100, 2) . " due to {$investor->getCompany()} ({$investor->getPercentageOwned()}% equity).",
                ['type' => 'investor_payout', 'investorId' => (string) $investor->getId(), 'amount' => $payout],
            );
        }

        // Manager dividend: a % of season profit, if the season was profitable.
        // The client is authoritative for the actual dividend calculation; this record
        // on the backend exists as an audit trail and leaderboard source of truth.
        $dividendPaidPence = 0;
        $gameConfig = $this->gameConfigRepository->getConfig();
        $dividendPercent = $gameConfig !== null ? $gameConfig->getManagerDividendPercent() : 5.0;

        if ($annualProfit > 0) {
            $dividendPaidPence = (int) round($annualProfit * ($dividendPercent / 100));
            $club->setTotalCareerEarnings($club->getTotalCareerEarnings() + $dividendPaidPence);
        }

        $this->em->flush();

        return ['dividendPaidPence' => $dividendPaidPence];
    }

    // -------------------------------------------------------------------------
    // Sponsor contract health check
    // -------------------------------------------------------------------------

    public function checkSponsorContracts(Club $club, int $currentReputation): void
    {
        $now = new \DateTimeImmutable();

        foreach ($club->getSponsors() as $sponsor) {
            if ($sponsor->getStatus() !== SponsorStatus::ACTIVE) {
                continue;
            }

            $sponsor->checkReputationThresholds($currentReputation);

            // Mark completed if contract end date has passed
            if ($sponsor->getContractEndDate() !== null && $sponsor->getContractEndDate() <= $now) {
                $sponsor->setStatus(SponsorStatus::COMPLETED);
            }
        }
    }

}
