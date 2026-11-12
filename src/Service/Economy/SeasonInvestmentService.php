<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\Club;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Exception\Setup\SetupActionException;
use App\Repository\SeasonRecordRepository;
use App\Repository\UserLedgerRepository;
use App\Service\UserLedgerService;

/**
 * The owner putting their own money into one of their clubs, once a season.
 *
 * MODELLED ON ClubFundingService, which is the other place account credit moves into a club —
 * same natural-key idempotency, same "the server debits the account and the DEVICE credits the
 * club" split, same exception type so the client has one error contract for both.
 *
 * ── THE CLUB IS NEVER CREDITED SERVER-SIDE ────────────────────────────────────────────────
 *
 * The debit from `UserLedger` is real and durable. The credit to `club.balance` is an
 * INSTRUCTION in the response that the device applies. `SyncService::process()` assigns
 * `club.balance` absolutely from the device on every sync, so a server-side credit there would
 * look right until the next sync and then silently vanish — and there is no server-side
 * authority to repair it from afterwards. That is the single most important thing to know about
 * money in this codebase; see `.context/stages/01_overview/output/tribal-knowledge.md`.
 *
 * ── WHICH SEASON IT IS, AND WHY NOT `club.currentSeason` ──────────────────────────────────
 *
 * `club.currentSeason` is DEVICE-MIRRORED. `SyncService::process()` overwrites it from the
 * payload, so a client could report season 50 and — because the ceiling grows with the
 * inflation index — both raise its own ceiling and claim for seasons it never played.
 *
 * So the season comes from a figure the backend owns outright: **the number of `SeasonRecord`
 * rows this club has.** Those are written only by `LeagueService::concludeSeason()`, one per
 * `(club, season)` behind `uq_season_record_club_season`, so the count advances by at most one
 * per actual concluded season. A device claiming season 50 writes ONE record and moves the
 * count from n to n+1 — it cannot jump the ceiling.
 *
 * Preferred over a new counter column because it needs no migration, no second write path to
 * keep in sync, and reuses a unique constraint that is already load-bearing.
 *
 * RESIDUAL EXPOSURE, STATED RATHER THAN HIDDEN: a late retro-conclude for a genuinely missed
 * season also adds a row, so it opens one extra investment window. That is bounded at one per
 * conclude that actually archives a season, and a conclude is a real event — far smaller than
 * trusting a claimed season number, which is unbounded.
 *
 * ── IDEMPOTENCY ───────────────────────────────────────────────────────────────────────────
 *
 * `(sourceKind, sourceRef, type)` with `sourceRef = "{clubId}:{season}"`, via the ledger's
 * `uq_user_ledger_backend_source` partial unique index. A retry of the same amount returns the
 * original debit; a DIFFERENT amount is refused rather than stacked, because a season is
 * invested in once and the second request is a new gift wearing the first one's key. Exactly
 * ClubFundingService's rule.
 */
final class SeasonInvestmentService
{
    /** Shared by the ledger row's sourceKind and the read that looks it up. */
    public const SOURCE_KIND = 'season_investment';

    public function __construct(
        private readonly UserLedgerRepository   $ledgerRepository,
        private readonly UserLedgerService      $ledgerService,
        private readonly SeasonRecordRepository $seasonRecordRepository,
        private readonly EconomyScaleResolver   $scaleResolver,
    ) {}

    /**
     * What the owner may invest right now, and whether they already have.
     *
     * The read behind `GET /api/club/season-investment`. Returns the same shape the write does,
     * so a client can render the screen from either without a second code path.
     *
     * @return array{invested: bool, season: int, investedPence: int, ceilingPence: int,
     *     remainingPence: int, balancePence: int, countryFactorBp: int, tierFactorBp: int,
     *     tier: int|null, creditClubPence: int}
     */
    public function state(User $user, Club $club): array
    {
        $season   = $this->seasonFor($club);
        $existing = $this->existingFor($club, $season);
        $invested = $existing !== null ? abs($existing->getAmountPence()) : 0;

        return $this->result(
            invested: false,
            season: $season,
            investedPence: $invested,
            club: $club,
            user: $user,
            creditClubPence: 0,
        );
    }

    /**
     * Move `$amountPence` from the owner's account into the club for this season.
     *
     * @return array{invested: bool, season: int, investedPence: int, ceilingPence: int,
     *     remainingPence: int, balancePence: int, countryFactorBp: int, tierFactorBp: int,
     *     tier: int|null, creditClubPence: int}
     *
     * @throws SetupActionException
     */
    public function invest(User $user, Club $club, int $amountPence): array
    {
        $season = $this->seasonFor($club);

        // ── The replay path, FIRST, before any validation ──────────────────────────────────
        //
        // Ahead of the amount and ceiling checks on purpose: a retry after a dropped response
        // must succeed even if the ceiling has since been lowered, or an operator re-tuning
        // the economy would turn a client's harmless retry into a permanent failure for a
        // debit that already happened.
        $existing = $this->existingFor($club, $season);

        if ($existing !== null) {
            $already = abs($existing->getAmountPence());

            if ($already !== $amountPence) {
                throw SetupActionException::alreadyInvestedThisSeason($already, $season);
            }

            return $this->result(
                invested: false,
                season: $season,
                investedPence: $already,
                club: $club,
                user: $user,
                // The ORIGINAL figure, so a client that lost the first response can still seed
                // its local balance from the replay.
                creditClubPence: $already,
            );
        }

        if ($amountPence <= 0) {
            throw SetupActionException::invalidAmount();
        }

        $ceiling = $this->ceilingFor($club, $season);

        // Zero is the shipped ceiling, so this is the refusal every request gets until an
        // operator authors a base figure — which is how the feature ships unreachable.
        if ($amountPence > $ceiling) {
            throw SetupActionException::aboveSeasonCeiling($amountPence, $ceiling, $season);
        }

        $balancePence = $this->ledgerRepository->getCurrentBalance($user);

        if ($amountPence > $balancePence) {
            throw SetupActionException::insufficientCredit($balancePence, $amountPence);
        }

        // ATOMIC, and the lock is inside append(): it takes a per-user advisory lock for the
        // duration of its transaction, so two requests for the same account cannot both read
        // the same balance and fork the chain. The check above is the friendly refusal; this is
        // what makes it true under concurrency.
        $this->ledgerService->append(
            $user,
            $club,
            UserLedgerEntryType::SEASON_INVESTMENT,
            // NEGATIVE: a debit. append() throws on a positive one.
            -$amountPence,
            self::SOURCE_KIND,
            $this->sourceRef($club, $season),
            sprintf('Season %d investment into %s', $season, $club->getName()),
        );

        return $this->result(
            invested: true,
            season: $season,
            investedPence: $amountPence,
            club: $club,
            user: $user,
            creditClubPence: $amountPence,
        );
    }

    /**
     * The season this club is playing, from a figure the BACKEND owns.
     *
     * `countByClub()` counts `SeasonRecord` rows, which only `concludeSeason()` writes, one per
     * `(club, season)`. Seasons concluded + 1 is the season in progress — a club that has
     * concluded nothing is in season 1. See the class docblock on why not `club.currentSeason`.
     */
    public function seasonFor(Club $club): int
    {
        return $this->seasonRecordRepository->countByClub($club) + 1;
    }

    /** The ledger key. One investment per club per season, so both are in it. */
    public function sourceRef(Club $club, int $season): string
    {
        return $club->getId()->toRfc4122() . ':' . $season;
    }

    /**
     * The resolved ceiling for this club's country and tier, this season.
     *
     * A club with no league yet has no tier to scale by and gets 0 — it cannot have concluded a
     * season either, so this is a pre-setup state rather than a configuration to work around.
     */
    public function ceilingFor(Club $club, int $season): int
    {
        $tier = $club->getCurrentLeague()?->getTier();

        if ($tier === null) {
            return 0;
        }

        return $this->scaleResolver->seasonInvestmentCeilingPence((string) $club->getCountry(), $tier, $season);
    }

    private function existingFor(Club $club, int $season): ?\App\Entity\UserLedger
    {
        return $this->ledgerRepository->findByBackendSource(
            self::SOURCE_KIND,
            $this->sourceRef($club, $season),
            UserLedgerEntryType::SEASON_INVESTMENT,
        );
    }

    /**
     * @return array{invested: bool, season: int, investedPence: int, ceilingPence: int,
     *     remainingPence: int, balancePence: int, countryFactorBp: int, tierFactorBp: int,
     *     tier: int|null, creditClubPence: int}
     */
    private function result(
        bool $invested,
        int $season,
        int $investedPence,
        Club $club,
        User $user,
        int $creditClubPence,
    ): array {
        $tier    = $club->getCurrentLeague()?->getTier();
        $ceiling = $this->ceilingFor($club, $season);
        $scale   = $tier !== null
            ? $this->scaleResolver->resolve((string) $club->getCountry(), $tier)
            : null;

        return [
            'invested'       => $invested,
            'season'         => $season,
            'investedPence'  => $investedPence,
            'ceilingPence'   => $ceiling,
            // Never negative, even if an operator lowered the ceiling after a debit — the money
            // is already gone and "you owe 40,000" is not a thing this screen can mean.
            'remainingPence' => max(0, $ceiling - $investedPence),
            'balancePence'   => $this->ledgerRepository->getCurrentBalance($user),
            // The breakdown, so an owner can see WHY their ceiling is what it is rather than
            // being handed an unexplained number. Factors, not money — the resolved ceiling
            // above is the money.
            'countryFactorBp' => $scale?->countryFactorBp ?? 10000,
            'tierFactorBp'    => $scale?->tierFactorBp ?? 10000,
            'tier'            => $tier,
            // WHAT THE DEVICE MUST ADD TO `club.balance`. The server does not and cannot do it
            // — see the class docblock. 0 on a read, and 0 on a refusal.
            'creditClubPence' => $creditClubPence,
        ];
    }
}
