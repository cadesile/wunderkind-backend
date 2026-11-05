<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\Club;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Repository\ClubFacilityRepository;
use App\Repository\UserLedgerRepository;
use App\Service\StartingGrantService;

/**
 * Where an account is in club setup, derived rather than recorded.
 *
 * WHY THIS IS NOT `GET /api/club/check`. That endpoint returns `{exists, clubId}`, is the
 * pre-club routing call every cold boot makes, and is deliberately cheap — loading it with
 * setup progress would make the hub's first call do work for a screen the player may never
 * reach. docs/api/setup-flow.md #6 recommended a separate endpoint for exactly that reason.
 *
 * EVERY FIELD HERE IS DERIVED FROM WHAT WAS ACTUALLY BILLED OR BUILT — ledger rows and
 * ClubFacility levels — and none of it is a progress flag. A flag column can disagree with the
 * money; a read of the money cannot. So a mid-BUILD kill resumes against the truth: a site that
 * was charged reports charged, whatever the client thought it had got through.
 *
 * READ-ONLY AND FREE. Nothing here spends, queues or enriches anything.
 */
final class ClubSetupStateService
{
    public function __construct(
        private readonly ClubFacilityBuildService $buildService,
        private readonly ClubFundingService       $fundingService,
        private readonly SetupQuizService         $quizService,
        private readonly ClubFacilityRepository   $clubFacilityRepository,
        private readonly UserLedgerRepository     $ledgerRepository,
        private readonly StartingGrantService     $startingGrantService,
    ) {}

    /**
     * @return array{clubId: string, balancePence: int,
     *     grant: array{expectedPence: int, paidPence: int|null},
     *     sites: array<string, array{charged: bool, chargedPence: int, levels: array<string, int>}>,
     *     funding: array{funded: bool, stakedPence: int|null, minimumPence: int},
     *     quiz: array{total: int, answered: int, correct: int, complete: bool,
     *         bonusPaid: bool, bonusPence: int},
     *     complete: bool}
     */
    public function stateFor(User $user, Club $club): array
    {
        $sites = [];

        foreach ($this->buildService->knownSites() as $site) {
            $templates = $this->buildService->templatesForSite($site);
            $slugs     = array_keys($templates);

            $charge = $this->ledgerRepository->findByBackendSource(
                ClubFacilityBuildService::SOURCE_KIND,
                $this->buildService->sourceRef($club, $site),
                // Per-site type, so this read finds the row the build actually wrote.
                $this->buildService->ledgerType($site),
            );

            $levels = array_fill_keys($slugs, 0);
            foreach ($this->clubFacilityRepository->findBy(['club' => $club, 'facilitySlug' => $slugs]) as $facility) {
                $levels[$facility->getFacilitySlug()] = $facility->getLevel();
            }

            $sites[$site] = [
                'charged'      => $charge !== null,
                'chargedPence' => $charge === null ? 0 : abs($charge->getAmountPence()),
                'levels'       => $levels,
            ];
        }

        $staked = $this->fundingService->stakedPence($club);
        $quiz   = $this->quizService->quizFor($user);

        $grantPaid = $this->ledgerRepository->findByBackendSource(
            StartingGrantService::SOURCE_KIND,
            (string) $user->getId(),
            UserLedgerEntryType::STARTING_GRANT,
        );

        return [
            'clubId'       => $club->getId()->toRfc4122(),
            'balancePence' => $this->ledgerRepository->getCurrentBalance($user),
            'grant'        => [
                // What config says this account's grant is, and what was actually written. They
                // differ only before the avatar is saved (nothing written yet) or when the
                // configured range is zero — both legitimate, and the client must read the real
                // figure rather than computing one. See docs/api/setup-flow.md #3.
                'expectedPence' => $this->startingGrantService->amountFor($user),
                'paidPence'     => $grantPaid?->getAmountPence(),
            ],
            'sites'        => $sites,
            'funding'      => [
                'funded'       => $staked !== null,
                'stakedPence'  => $staked,
                'minimumPence' => $this->fundingService->minimumPence(),
            ],
            'quiz'         => [
                'total'      => $quiz['total'],
                'answered'   => $quiz['answered'],
                'correct'    => $quiz['correct'],
                'complete'   => $quiz['complete'],
                'bonusPaid'  => $quiz['bonus']['paid'],
                'bonusPence' => $quiz['bonus']['pence'],
            ],
            // The one thing that actually gates entering the game: the club has capital. Builds
            // and the quiz are optional, so neither is part of this.
            'complete'     => $staked !== null,
        ];
    }
}
