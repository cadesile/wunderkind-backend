<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Enum\SponsorStatus;

class EconomicService
{
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
