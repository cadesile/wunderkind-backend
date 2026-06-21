<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\GameConfig;
use App\Entity\Player;
use App\Enum\InvestorTier;
use App\Enum\SponsorStatus;
use App\Repository\GameConfigRepository;
use App\Repository\InvestorRepository;
use App\Repository\SponsorRepository;
use Doctrine\ORM\EntityManagerInterface;

class EconomicService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboxService           $inboxService,
        private readonly InvestorRepository     $investorRepository,
        private readonly SponsorRepository      $sponsorRepository,
        private readonly GameConfigRepository   $gameConfigRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Offer generation
    // -------------------------------------------------------------------------

    public function generateSponsorOffer(Club $club): array
    {
        $reputation = $club->getReputation();

        [$tier, $baseMonthly, $durationMonths] = match (true) {
            $reputation >= 500 => ['large',  50_000_00,  24],
            $reputation >= 200 => ['medium', 15_000_00,  12],
            default            => ['small',   3_000_00,   6],
        };

        // ±30 % noise
        $multiplier    = 0.7 + (random_int(0, 60) / 100);
        $monthlyPayment = (int) round($baseMonthly * $multiplier);

        return [
            'company'                => 'Sponsor Co.',
            'tier'                   => $tier,
            'monthlyPayment'         => $monthlyPayment,
            'durationMonths'         => $durationMonths,
            'reputationMinThreshold' => max(0, $reputation - 50),
            'reputationBonusThreshold' => $reputation + 100,
        ];
    }

    public function generateInvestorOffer(Club $club): array
    {
        $reputation = $club->getReputation();

        [$tier, $minAmount, $maxAmount, $minPct, $maxPct] = match (true) {
            $reputation >= 500 => [InvestorTier::PRIVATE_EQUITY, 200_000_00, 500_000_00, 5.0, 15.0],
            $reputation >= 200 => [InvestorTier::VC,             100_000_00, 200_000_00, 5.0, 12.0],
            default            => [InvestorTier::ANGEL,           50_000_00, 100_000_00, 2.0,  8.0],
        };

        $investmentAmount = random_int($minAmount, $maxAmount);
        $percentageOwned  = round($minPct + (random_int(0, 100) / 100) * ($maxPct - $minPct), 2);

        if (!$club->canAcceptInvestor($percentageOwned)) {
            return [];
        }

        return [
            'company'          => 'Investment Co.',
            'tier'             => $tier->value,
            'investmentAmount' => $investmentAmount,
            'percentageOwned'  => $percentageOwned,
        ];
    }

    // -------------------------------------------------------------------------
    // Player market value
    // -------------------------------------------------------------------------

    public function calculatePlayerMarketValue(Player $player): int
    {
        $baseValue = 10_000;

        $abilityFactor    = $player->getCurrentAbility() / 50;
        $potentialFactor  = 1 + ($player->getPotential() - $player->getCurrentAbility()) / 200;

        // Age factor: peaks at 17–19, drops sharply after 20
        $now = new \DateTimeImmutable();
        $age = (int) $now->diff($player->getDateOfBirth())->y;
        $ageFactor = match (true) {
            $age <= 14 => 0.5,
            $age <= 16 => 0.8,
            $age <= 19 => 1.0,
            $age === 20 => 0.7,
            default    => 0.3,
        };

        // Personality factor: weighted average of loyalty, professionalism, determination (Unified 1-20 scale)
        $p = $player->getPersonality();
        $personalityFactor = 1 + (($p->getLoyalty() + $p->getProfessionalism() + $p->getDetermination()) / 60 - 0.5) * 0.2;

        $reputationFactor = 1;

        return (int) round($baseValue * $abilityFactor * $potentialFactor * $ageFactor * $personalityFactor * $reputationFactor);
    }

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
