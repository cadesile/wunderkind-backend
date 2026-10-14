<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Tier;
use App\Repository\PlayerRepository;

/**
 * Draws a youth pool (ages 12–14) out of the existing Player pool for GET /api/players/youth.
 *
 * DRAWS, never generates. The device matches these players to a club's reputation tier
 * itself, for its own club and every NPC club in the pyramid, so the response has to carry
 * a usable spread of quality rather than a single tier the backend picked.
 *
 * ── Why the shape of the result is not guaranteed ────────────────────────────────────
 * Because this sources rather than generates, the quality and nationality spreads are
 * properties of whatever is in the pool, not knobs this service can turn. It can only
 * stratify the draw — ask for a target number per (tier, nationality) cell — and then
 * report what it actually got. When a cell is short it backfills from the remaining pool
 * rather than returning fewer than $limit, and the response carries the realised
 * distribution so an operator seeding the pool can see which cell is empty. A short or
 * skewed response is an empty shelf, not a bug, and must be legible as such.
 *
 * Non-consuming: nothing is deleted or marked. Youth intake runs for every club in the
 * pyramid, so a consuming draw would drain the pool within a handful of calls. The
 * consequence, which the client contract states explicitly, is that two devices can be
 * handed the same player id — these are upserts, not an exclusive allocation.
 *
 * Pool players carry no club association at all (Player has no club FK — see the Pool
 * Lifecycle section of CLAUDE.md), so "no club association" needs no special handling.
 */
class YouthPoolService
{
    public const MIN_AGE = 12;
    public const MAX_AGE = 14;

    public const DEFAULT_LIMIT = 200;
    public const MAX_LIMIT     = 500;

    /**
     * Share of the draw targeted at each tier, banded on POTENTIAL (see
     * PlayerSnapshotSerializer for why not currentAbility). Local is the bulk of any youth
     * intake; elite is the rare "one to watch". Weights are relative, not percentages —
     * they are normalised against whichever tiers the pool can actually supply.
     */
    private const TIER_WEIGHTS = [
        'local'    => 55,
        'regional' => 28,
        'national' => 13,
        'elite'    => 4,
    ];

    /**
     * Share of each tier that should carry the requested country's nationality. Lower tiers
     * are overwhelmingly local lads; the elite band is where a club realistically looks
     * abroad, so it is the most internationally mixed. Percentages, per tier.
     */
    private const HOME_NATIONALITY_PERCENT = [
        'local'    => 95,
        'regional' => 85,
        'national' => 70,
        'elite'    => 45,
    ];

    public function __construct(
        private readonly PlayerRepository $players,
    ) {}

    /**
     * @return array{
     *     players: \App\Entity\Player[],
     *     requested: int,
     *     poolSize: int,
     *     tierCounts: array<string, int>,
     *     nationalityMatched: int
     * }
     */
    public function draw(string $nationality, int $limit): array
    {
        [$dobFrom, $dobTo] = self::dobWindow();

        $poolSize = $this->players->countYouthInPool($dobFrom, $dobTo);

        $drawn      = [];
        $excludeIds = [];

        foreach (self::TIER_WEIGHTS as $tierValue => $weight) {
            [$potMin, $potMax] = Tier::from($tierValue)->scoreRange();

            $tierTarget = (int) round($limit * $weight / array_sum(self::TIER_WEIGHTS));
            if ($tierTarget <= 0) {
                continue;
            }

            $homeTarget = (int) round($tierTarget * self::HOME_NATIONALITY_PERCENT[$tierValue] / 100);

            foreach ([[$homeTarget, true], [$tierTarget - $homeTarget, false]] as [$cellTarget, $matchNationality]) {
                if ($cellTarget <= 0) {
                    continue;
                }

                $rows = $this->players->findYouthStratum(
                    $dobFrom,
                    $dobTo,
                    $potMin,
                    $potMax,
                    $cellTarget,
                    $nationality,
                    $matchNationality,
                    $excludeIds,
                );

                foreach ($rows as $row) {
                    $drawn[]      = $row;
                    $excludeIds[] = (string) $row->getId();
                }
            }
        }

        // Backfill: every cell above is a best-effort ask, so an under-supplied pool leaves
        // the draw short. One unconstrained sweep over the whole age window tops it up with
        // whatever exists, preferring the requested nationality first so a thin pool still
        // honours the country weighting as far as it can.
        foreach ([true, false] as $matchNationality) {
            $shortfall = $limit - count($drawn);
            if ($shortfall <= 0) {
                break;
            }

            $rows = $this->players->findYouthStratum(
                $dobFrom,
                $dobTo,
                null,
                null,
                $shortfall,
                $nationality,
                $matchNationality,
                $excludeIds,
            );

            foreach ($rows as $row) {
                $drawn[]      = $row;
                $excludeIds[] = (string) $row->getId();
            }
        }

        // Strata arrive grouped by tier; shuffle so the client isn't handed a list that is
        // sorted by quality, which would bias anything that reads it top-down.
        shuffle($drawn);

        $tierCounts = ['local' => 0, 'regional' => 0, 'national' => 0, 'elite' => 0];
        $matched    = 0;
        foreach ($drawn as $player) {
            $tierCounts[Tier::fromScore($player->getPotential())->value]++;
            if ($player->getNationality() === $nationality) {
                $matched++;
            }
        }

        return [
            'players'            => $drawn,
            'requested'          => $limit,
            'poolSize'           => $poolSize,
            'tierCounts'         => $tierCounts,
            'nationalityMatched' => $matched,
        ];
    }

    /**
     * The date_of_birth range that is MIN_AGE..MAX_AGE inclusive today.
     *
     * Deliberately a DOB window rather than a computed age: it is the only form an index
     * can serve, and it avoids standing a second age formula next to
     * PlayerGenerationService's. Both ends are inclusive — someone whose 15th birthday is
     * tomorrow is still 14 today, hence the +1 day on the lower bound.
     *
     * Known edge, inherited from the generator and not introduced here: PHP rolls
     * "Feb 29 minus N years" to Mar 1 when the target year is not a leap year. On 29
     * February this window is therefore one day narrower at the bottom than it looks. It
     * is the same leap-day arithmetic behind the pre-existing PlayerGenerationServiceTest
     * ::testAgeIsWithinRange flake, which fires only on that date.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function dobWindow(): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            $today->modify('-' . (self::MAX_AGE + 1) . ' years')->modify('+1 day'),
            $today->modify('-' . self::MIN_AGE . ' years'),
        ];
    }
}
