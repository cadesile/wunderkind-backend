<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Academy;
use App\Entity\Player;
use App\Entity\Transfer;
use App\Enum\InvestorTier;
use App\Enum\PlayerStatus;
use App\Enum\SponsorStatus;
use App\Enum\TransferType;
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
    ) {}

    // -------------------------------------------------------------------------
    // Offer generation
    // -------------------------------------------------------------------------

    public function generateSponsorOffer(Academy $academy): array
    {
        $reputation = $academy->getReputation();

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

    public function generateInvestorOffer(Academy $academy): array
    {
        $reputation = $academy->getReputation();

        [$tier, $minAmount, $maxAmount, $minPct, $maxPct] = match (true) {
            $reputation >= 500 => [InvestorTier::PRIVATE_EQUITY, 200_000_00, 500_000_00, 5.0, 15.0],
            $reputation >= 200 => [InvestorTier::VC,             100_000_00, 200_000_00, 5.0, 12.0],
            default            => [InvestorTier::ANGEL,           50_000_00, 100_000_00, 2.0,  8.0],
        };

        $investmentAmount = random_int($minAmount, $maxAmount);
        $percentageOwned  = round($minPct + (random_int(0, 100) / 100) * ($maxPct - $minPct), 2);

        if (!$academy->canAcceptInvestor($percentageOwned)) {
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

        // Personality factor: weighted average of loyalty, teamwork, leadership
        $p = $player->getPersonality();
        $personalityFactor = 1 + (($p->getLoyalty() + $p->getTeamwork() + $p->getLeadership()) / 300 - 0.5) * 0.2;

        $reputationFactor = 1 + $player->getAcademy()?->getReputation() / 1000 ?? 0;

        return (int) round($baseValue * $abilityFactor * $potentialFactor * $ageFactor * $personalityFactor * $reputationFactor);
    }

    // -------------------------------------------------------------------------
    // Financial year-end processing
    // -------------------------------------------------------------------------

    public function processFinancialYearEnd(Academy $academy): void
    {
        $annualProfit = $academy->calculateAnnualProfit();
        $now          = new \DateTimeImmutable();

        foreach ($academy->getInvestors() as $investor) {
            if (!$investor->isActive()) {
                continue;
            }

            $payout = $investor->calculateAnnualPayout($annualProfit);
            $investor->setLastPayoutAt($now);

            $this->inboxService->sendSystemNotification(
                $academy,
                "Annual investor payout: {$investor->getCompany()}",
                "Annual profit-sharing payout of £" . number_format($payout / 100, 2) . " due to {$investor->getCompany()} ({$investor->getPercentageOwned()}% equity).",
                ['type' => 'investor_payout', 'investorId' => (string) $investor->getId(), 'amount' => $payout],
            );
        }

        $this->em->flush();
    }

    // -------------------------------------------------------------------------
    // Sponsor contract health check
    // -------------------------------------------------------------------------

    public function checkSponsorContracts(Academy $academy, int $currentReputation): void
    {
        $now = new \DateTimeImmutable();

        foreach ($academy->getSponsors() as $sponsor) {
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

    // -------------------------------------------------------------------------
    // Age-out checks
    // -------------------------------------------------------------------------

    public function checkAgeOutPlayers(Academy $academy, int $currentWeek, \DateTimeImmutable $clientTimestamp): void
    {
        foreach ($academy->getPlayers() as $player) {
            if ($player->getStatus() !== PlayerStatus::ACTIVE) {
                continue;
            }

            // Compute forced-sale week on first encounter
            if ($player->getForcedSaleWeek() === null) {
                $forcedSaleWeek = $this->computeForcedSaleWeek($player, $currentWeek, $clientTimestamp);
                if ($forcedSaleWeek !== null) {
                    $player->setForcedSaleWeek($forcedSaleWeek);
                }
            }

            $weeksUntil21 = $player->getWeeksUntil21($currentWeek);

            // Send warning at 4 weeks
            if ($weeksUntil21 <= 4 && !$player->isAgeOutWarningIssued()) {
                $this->inboxService->sendAgeOutWarning($player, $weeksUntil21);
                $player->setAgeOutWarningIssued(true);
            }

            // Execute forced sale
            if ($player->getForcedSaleWeek() !== null
                && $currentWeek >= $player->getForcedSaleWeek()
                && !$player->isForcedSaleExecuted()
            ) {
                $this->executeForcedSale($player, $academy);
            }
        }

        $this->em->flush();
    }

    public function executeForcedSale(Player $player, Academy $academy): Transfer
    {
        $marketValue = $this->calculatePlayerMarketValue($player);

        $transfer = new Transfer(
            player:             $player,
            academy:            $academy,
            destinationClubName: 'Age-out (automatic)',
            type:               TransferType::SALE,
            occurredAt:         new \DateTimeImmutable(),
        );
        $transfer->setFee($marketValue);

        $player->setStatus(PlayerStatus::TRANSFERRED);
        $player->setForcedSaleExecuted(true);
        $player->setAcademy(null);

        $this->em->persist($transfer);

        $this->inboxService->sendForcedSaleNotification($player, $marketValue);

        return $transfer;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Compute the game week at which a player turns 21, based on their DOB
     * and the client timestamp mapped to the current game week.
     */
    private function computeForcedSaleWeek(Player $player, int $currentWeek, \DateTimeImmutable $clientTimestamp): ?int
    {
        $dob = $player->getDateOfBirth();

        // Date when the player turns 21
        $turns21At = $dob->modify('+21 years');

        $diff = $clientTimestamp->diff($turns21At);

        // If already past 21, forced sale should happen immediately
        if ($turns21At <= $clientTimestamp) {
            return $currentWeek;
        }

        // Convert remaining real-time days to approximate game weeks (1:1 ratio assumed)
        $daysRemaining  = (int) $diff->days;
        $weeksRemaining = (int) ceil($daysRemaining / 7);

        return $currentWeek + $weeksRemaining;
    }
}
