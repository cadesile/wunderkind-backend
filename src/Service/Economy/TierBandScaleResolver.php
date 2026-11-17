<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Repository\StarterConfigRepository;
use App\Service\WorldInitializationService;

/**
 * How far below the top of the pyramid a tier sits, as a fraction — the tier scalar the
 * `legacy` wage model can actually use.
 *
 * ── Why this exists, when EconomyScaleResolver already scales things by tier ──────────────
 *
 * `EconomyScaleResolver` is the right answer and cannot be used here: it resolves from
 * AUTHORED TURNOVER and returns `isResolvable === false` until an operator fills that table
 * in, which no deployed box has done. Everything that depends on it is therefore inert under
 * `legacy`, which is the model every box is actually running.
 *
 * `starter_config.league_ability_ranges` is the opposite: authored per country per tier
 * already, on every box, because it is what generates players. So it is the one tier ladder
 * that exists under `legacy` — and critically it is **the same axis players are priced on**,
 * since a legacy player's wage scales with its ability and its ability comes from this band.
 * Scaling staff by it makes staff and players move together by construction, rather than by a
 * second table somebody has to remember to keep in step.
 *
 * ── Midpoints, and both ends from the same resolution ────────────────────────────────────
 *
 * The scalar is `midpoint(tier) / midpoint(topTier)`. Both ends go through the same
 * precedence (authored → `WorldInitializationService::ABILITY_RANGES` → a flat fallback), so
 * a country that is authored at one end and defaulted at the other cannot produce a ratio
 * built from two different tables.
 *
 * Returns 1.0 — i.e. "no scaling" — for tier 1, for an unknown tier, and whenever the anchor
 * cannot be resolved to something positive. A scalar that silently became 0 would price a
 * whole tier's staff at nothing, which reads as a feature rather than as missing data.
 */
class TierBandScaleResolver
{
    /** The tier the authored bands are anchored at: the top of every pyramid. */
    public const ANCHOR_TIER = 1;

    public function __construct(private readonly StarterConfigRepository $starterConfigRepository) {}

    /**
     * `midpoint(country, tier) / midpoint(country, 1)`, clamped to (0, 1].
     *
     * Never above 1: the anchor is the top tier, so a band authored ABOVE tier 1's would
     * otherwise inflate that tier's staff bill rather than leave it at the authored figure.
     * An operator authoring a deeper tier richer than tier 1 is a mistake, and the safe
     * reading of a mistake here is "no discount", not "a premium".
     */
    public function scaleFor(string $country, int $tier): float
    {
        if ($tier <= self::ANCHOR_TIER) {
            return 1.0;
        }

        $anchor = $this->midpoint($country, self::ANCHOR_TIER);
        if ($anchor <= 0.0) {
            return 1.0;
        }

        $here = $this->midpoint($country, $tier);
        if ($here <= 0.0) {
            return 1.0;
        }

        return min(1.0, $here / $anchor);
    }

    /**
     * The midpoint of a tier's authored ability band.
     *
     * The precedence mirrors `WorldInitializationService::resolveAbilityRange()` — authored
     * row, else the per-tier const, else a flat floor — because that is the method that
     * decides what a player at this tier actually IS, and a scalar derived from a different
     * precedence would describe a pyramid the generator is not building.
     *
     * `min > 0` rather than `isset` is deliberate and matches that same method:
     * `league_ability_ranges` is written by an admin grid that can leave a country's row
     * present but zeroed, and a zeroed band means "unauthored", not "a tier of ability-zero
     * players". (`StarterPackService::resolveAbilityRange()` uses `isset` instead and so
     * disagrees on exactly that row — a pre-existing divergence, noted here because this
     * class deliberately follows the generator's copy.)
     */
    private function midpoint(string $country, int $tier): float
    {
        $ranges     = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();
        $configured = $ranges[$country][(string) $tier] ?? null;

        $band = ($configured && (int) ($configured['min'] ?? 0) > 0)
            ? ['min' => (int) $configured['min'], 'max' => (int) $configured['max']]
            : (WorldInitializationService::ABILITY_RANGES[$tier] ?? ['min' => 5, 'max' => 35]);

        return ((float) $band['min'] + (float) $band['max']) / 2.0;
    }
}
