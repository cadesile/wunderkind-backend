<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionEntrantRepository;

/**
 * Dev/testing convenience: once an instance has at least one entrant, and its template has
 * opted in (CompetitionTemplate::$autoFillSpoofEntrants), automatically fills every
 * remaining slot with spoof entrants a fixed delay after that first entrant registered — so
 * a solo tester isn't stuck waiting on real registrants to fill a bracket.
 *
 * Deliberately no separate "already auto-filled" flag: `findEligibleForAutoFill()` only ever
 * returns still-REGISTERING instances, and filling to capacity locks the instance (via
 * CompetitionRegistrationService::register(), inside spoofAllEntrants()'s own registration
 * calls) — so a fully-filled instance naturally drops out of eligibility on its own. If a
 * fill is ever interrupted partway (leaving the instance short of capacity, still
 * REGISTERING, delay already elapsed), the next tick simply continues filling the rest —
 * self-healing rather than a bug to guard against.
 */
class CompetitionAutoFillService
{
    public function __construct(
        private readonly ActiveCompetitionRepository $activeCompetitionRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionSpoofEntrantService $spoofEntrantService,
    ) {}

    /** @return int Number of instances auto-filled this call. */
    public function autoFillDueInstances(\DateTimeImmutable $now): int
    {
        $filled = 0;

        foreach ($this->activeCompetitionRepository->findEligibleForAutoFill() as $activeCompetition) {
            $earliestEntrant = $this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition)[0] ?? null;
            if ($earliestEntrant === null) {
                // Defensive only — findEligibleForAutoFill()'s EXISTS clause already guarantees this.
                continue;
            }

            $dueAt = $earliestEntrant->getRegisteredAt()
                ->modify(sprintf('+%d minutes', $activeCompetition->getTemplate()->getAutoFillDelayMinutes()));
            if ($now < $dueAt) {
                continue;
            }

            $this->spoofEntrantService->spoofAllEntrants($activeCompetition);
            $filled++;
        }

        return $filled;
    }
}
