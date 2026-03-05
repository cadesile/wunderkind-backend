<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Academy;
use App\Entity\Player;
use App\Entity\Transfer;
use App\Enum\InvestorTier;
use App\Enum\PlayerStatus;
use App\Enum\SponsorStatus;
use App\Repository\InvestorRepository;
use App\Repository\SponsorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class EconomicService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboxService           $inboxService,
        private readonly InvestorRepository     $investorRepository,
        private readonly SponsorRepository      $sponsorRepository,
        private readonly LoggerInterface        $logger,
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
            $academy->addFunds(-$payout);

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
        $playersToDelete = [];

        foreach ($academy->getPlayers() as $player) {
            if ($player->getStatus() !== PlayerStatus::ACTIVE) {
                continue;
            }

            $age = $this->calculateAge($player->getDateOfBirth(), $clientTimestamp);

            // Send warning when age is 20 and within 4 weeks of turning 21
            if ($age === 20 && !$player->isAgeOutWarningIssued()) {
                $weeksRemaining = $this->weeksUntilAge21($player->getDateOfBirth(), $clientTimestamp);
                if ($weeksRemaining <= 4) {
                    $this->inboxService->sendAgeOutWarning($player, $weeksRemaining);
                    $player->setAgeOutWarningIssued(true);
                }
            }

            // Hard delete at age 21 — collect for removal after iteration
            if ($age >= 21 && !$player->isForcedSaleExecuted()) {
                $this->inboxService->sendSystemNotification(
                    $academy,
                    'Player Aged Out: ' . $player->getFullName(),
                    sprintf(
                        '%s has turned 21 and left the academy. All records have been removed.',
                        $player->getFullName()
                    ),
                    ['type' => 'age_out', 'player_id' => $player->getId()->toRfc4122()],
                );
                // Mark flag to prevent duplicate processing within same flush cycle
                $player->setForcedSaleExecuted(true);
                $playersToDelete[] = $player;
            }
        }

        // Hard delete collected players — after the loop to avoid collection mutation issues
        foreach ($playersToDelete as $player) {
            // Remove transfers first (DB-level ON DELETE CASCADE also handles this, belt-and-braces)
            $transfers = $this->em->getRepository(Transfer::class)->findBy(['player' => $player]);
            foreach ($transfers as $transfer) {
                $this->em->remove($transfer);
            }

            // Guardian is cascade: remove in Doctrine mapping, handled automatically
            $this->em->remove($player);

            $this->logger->info('Player aged out and permanently deleted', [
                'player_id'  => $player->getId()->toRfc4122(),
                'academy_id' => $academy->getId()->toRfc4122(),
                'week'       => $currentWeek,
            ]);
        }

        $this->em->flush();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function calculateAge(\DateTimeImmutable $dob, \DateTimeImmutable $currentDate): int
    {
        return (int) $currentDate->diff($dob)->y;
    }

    private function weeksUntilAge21(\DateTimeImmutable $dob, \DateTimeImmutable $currentDate): int
    {
        $age21Date = $dob->modify('+21 years');
        if ($age21Date <= $currentDate) {
            return 0;
        }
        return (int) ceil($currentDate->diff($age21Date)->days / 7);
    }
}
