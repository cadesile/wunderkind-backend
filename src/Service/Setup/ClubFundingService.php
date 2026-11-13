<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\Club;
use App\Entity\ClubFacility;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Exception\Setup\SetupActionException;
use App\Repository\ClubFacilityRepository;
use App\Repository\FacilityTemplateRepository;
use App\Repository\StarterConfigRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Facility\FacilityCostCalculator;
use App\Service\UserLedgerService;

/**
 * The stake: moving credit out of the account and into a new club as its opening capital.
 *
 * THIS IS THE ONE DEBIT IN THE WHOLE LIFE OF A GAME SESSION. The agreed financial flow is three
 * phases, and only the first and last touch the account:
 *
 *   1. SETUP — the player stakes some or all of their account credit. One atomic debit, here.
 *   2. GAMEPLAY — completely isolated. Nothing queries, locks or updates the account balance.
 *   3. DIVIDEND — the game writes back, crediting the account. That is DIVIDEND_DRAW, detected
 *      in the sync payload by UserLedgerService::recordDividendDraws(), and it is the only
 *      inbound path.
 *
 * WHICH IS WHY THIS SERVICE DOES NOT SET `club.balance`. The staked figure is returned to the
 * caller and the client seeds its own local capital with it. SyncService::process() assigns
 * `club.balance` absolutely from the device on every sync, so writing it here would produce a
 * number that looks right until the first sync and then disappears — the same trap documented
 * on PlotClaimController.
 *
 * NO FLOOR UNLESS AN OPERATOR SETS ONE. The client's funding step offers "a partial sum or 100%
 * of the available balance", and refusing a stake the player was told they could make is a
 * worse failure than letting them underfund a club they can top up from a dividend later. So
 * `StarterConfig::$clubFundingMinimumPence` defaults to zero, meaning no minimum, and the
 * projection below is returned for the UI to warn with rather than to refuse on.
 *
 * THE PROJECTION IS HALF A PROJECTION, HONESTLY LABELLED. The server holds a club's facilities
 * and knows nothing about its staff: POST /api/market/assign deletes players and staff from the
 * pool on hire ("no club_id"), so the roster exists only on the device. Facility upkeep is
 * therefore the only running cost computable here; wages are the client's half, summed from
 * GET /api/market/data, where staff and scouts both now carry `weeklySalary`.
 *
 * AND THE HALF IT DOES COMPUTE IS EXACT IN ONE DIVISION ONLY. `FacilityCostCalculator` prices
 * upkeep as `weeklyUpkeepBase x 1.5^level` with NO TIER FACTOR — nothing in this service, that
 * calculator, or anything they call touches `EconomyScaleResolver`. The device applies a tier
 * factor on top, rising to about 3x at tier 1. So the figures below are right at the bottom of
 * the pyramid, where that factor is 1.0, and UNDERSTATE by up to 3x everywhere above it.
 *
 * Understating is the wrong direction, for the reason `FacilityCostCalculator::weeklyUpkeep()`
 * already gives about its own 1.5^level fix: a running cost that reads low is what lets a
 * player underfund a club. It is left as it is because the tier factor is the device's model
 * and duplicating it here would make the backend a second author of it — the same reasoning
 * that keeps wages on the device. The honest label is the fix; this is the label.
 *
 * IDEMPOTENT ON (club), by the ledger's `(sourceKind, sourceRef, type)` guard — the natural key
 * docs/api/setup-flow.md #5 chose over an Idempotency-Key header. A retry of the same amount
 * returns the original debit; a DIFFERENT amount is refused rather than stacked, because a club
 * is funded once and the second request is a new stake wearing the first one's key.
 */
final class ClubFundingService
{
    /** Shared by the ledger row's sourceKind and the setup-state read that looks it up. */
    public const SOURCE_KIND = 'club_funding';

    public function __construct(
        private readonly UserLedgerRepository       $ledgerRepository,
        private readonly UserLedgerService          $ledgerService,
        private readonly StarterConfigRepository    $starterConfigRepository,
        private readonly ClubFacilityRepository     $clubFacilityRepository,
        private readonly FacilityTemplateRepository $templateRepository,
        private readonly FacilityCostCalculator     $costCalculator,
    ) {}

    /**
     * Stake `$amountPence` of the account's credit into the club.
     *
     * @return array{funded: bool, stakedPence: int, balancePence: int, minimumPence: int,
     *     projection: array{weeks: int, facilityUpkeepPerWeekPence: int,
     *     facilityUpkeepPence: int, wagesIncluded: bool}}
     *
     * @throws SetupActionException
     */
    public function fund(User $user, Club $club, int $amountPence): array
    {
        $existing = $this->ledgerRepository->findByBackendSource(
            self::SOURCE_KIND,
            $this->sourceRef($club),
            UserLedgerEntryType::CLUB_FUNDING,
        );

        if ($existing !== null) {
            $already = abs($existing->getAmountPence());

            if ($already !== $amountPence) {
                throw SetupActionException::alreadyFunded($already);
            }

            // Exact replay — a retry after a dropped response. Returns the original figure so
            // the client can still seed its local capital from it.
            return $this->result(
                funded: false,
                stakedPence: $already,
                balancePence: $this->ledgerRepository->getCurrentBalance($user),
                club: $club,
            );
        }

        if ($amountPence <= 0) {
            throw SetupActionException::invalidAmount();
        }

        $minimumPence = $this->starterConfigRepository->getConfig()->getClubFundingMinimumPence();

        // Zero means "no minimum", not "minimum of zero" — see the column's docblock.
        if ($minimumPence > 0 && $amountPence < $minimumPence) {
            throw SetupActionException::belowFundingMinimum($amountPence, $minimumPence);
        }

        $balancePence = $this->ledgerRepository->getCurrentBalance($user);

        if ($amountPence > $balancePence) {
            throw SetupActionException::insufficientCredit($balancePence, $amountPence);
        }

        // ATOMIC, and the lock is inside append(): it takes a per-user advisory lock for the
        // duration of its transaction, so two funding requests for the same account cannot both
        // read the same balance and fork the chain. The balance check above is the friendly
        // refusal; this is what makes it true under concurrency.
        $this->ledgerService->append(
            $user,
            $club,
            UserLedgerEntryType::CLUB_FUNDING,
            // NEGATIVE: a debit. append() throws on a positive one.
            -$amountPence,
            self::SOURCE_KIND,
            $this->sourceRef($club),
            sprintf('Opening capital for %s', $club->getName()),
        );

        return $this->result(
            funded: true,
            stakedPence: $amountPence,
            balancePence: $this->ledgerRepository->getCurrentBalance($user),
            club: $club,
        );
    }

    /** The configured floor, or 0 when there is none. Zero means no minimum, not a minimum of zero. */
    public function minimumPence(): int
    {
        return $this->starterConfigRepository->getConfig()->getClubFundingMinimumPence();
    }

    /** What has already been staked into this club, or null if it has never been funded. */
    public function stakedPence(Club $club): ?int
    {
        $entry = $this->ledgerRepository->findByBackendSource(
            self::SOURCE_KIND,
            $this->sourceRef($club),
            UserLedgerEntryType::CLUB_FUNDING,
        );

        return $entry === null ? null : abs($entry->getAmountPence());
    }

    /** The ledger key. One stake per club, so the club id is the whole of it. */
    public function sourceRef(Club $club): string
    {
        return $club->getId()->toRfc4122();
    }

    /**
     * Weekly upkeep of everything this club has built.
     *
     * The server's half of the running-cost projection. A facility whose template has been
     * deleted contributes nothing rather than being guessed at — same rule
     * ClubValuationService::facilityInvestment() follows.
     */
    public function facilityUpkeepPerWeekPence(Club $club): int
    {
        $templates = [];
        foreach ($this->templateRepository->findAllOrderedByCategoryAndSort() as $template) {
            $templates[$template->getSlug()] = $template;
        }

        $total = 0;

        /** @var ClubFacility $facility */
        foreach ($this->clubFacilityRepository->findBy(['club' => $club]) as $facility) {
            $template = $templates[$facility->getFacilitySlug()] ?? null;

            if ($template === null) {
                continue;
            }

            $total += $this->costCalculator->weeklyUpkeep($template, $facility->getLevel());
        }

        return $total;
    }

    /**
     * @return array{funded: bool, stakedPence: int, balancePence: int, minimumPence: int,
     *     projection: array{weeks: int, facilityUpkeepPerWeekPence: int,
     *     facilityUpkeepPence: int, wagesIncluded: bool}}
     */
    private function result(bool $funded, int $stakedPence, int $balancePence, Club $club): array
    {
        $config  = $this->starterConfigRepository->getConfig();
        $weeks   = $config->getClubFundingProjectionWeeks();
        $upkeep  = $this->facilityUpkeepPerWeekPence($club);

        return [
            'funded'       => $funded,
            'stakedPence'  => $stakedPence,
            'balancePence' => $balancePence,
            'minimumPence' => $config->getClubFundingMinimumPence(),
            'projection'   => [
                'weeks'                      => $weeks,
                'facilityUpkeepPerWeekPence' => $upkeep,
                'facilityUpkeepPence'        => $upkeep * $weeks,
                // Stated rather than implied: the client must add its own wage total from
                // market/data, because the server has no roster to read one from.
                'wagesIncluded'              => false,
            ],
        ];
    }
}
