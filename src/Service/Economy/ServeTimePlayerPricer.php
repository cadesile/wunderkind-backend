<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\Player;
use App\Enum\EconomyWageModel;

/**
 * Prices a SHARED-POOL player at serve time, from the browsing club's country and tier.
 *
 * ── Why this exists as its own class ─────────────────────────────────────────────────────
 *
 * "Two populations, two treatments" governs where the wage model can apply at all. A
 * per-country row (a world-pack club's player) is generated for a known country and tier, so
 * it is priced at generation and the figure is stored — which is why flipping the model needs a
 * `WorldInitializationService::WORLD_PACK_VERSION` bump. A shared-pool row is ONE row served to
 * every country at every tier, so there is nothing to price it against at generation; it is
 * stored at the legacy figure and priced HERE.
 *
 * That rule was right but it lived inside `PlayerBrowseSerializer`, so it reached only the two
 * endpoints that happened to use that serializer. `GET /api/players/youth` serves pool players
 * through `PlayerSnapshotSerializer` and was therefore serving the stored legacy figure — a
 * surface outside the economy that nothing named. Extracting the rule gives it one home, so
 * "which surfaces price pool players" has a findable answer and the next pool-serving endpoint
 * has something obvious to call.
 *
 * ── Where this must NOT be used ──────────────────────────────────────────────────────────
 *
 * **Never inside `PlayerSnapshotSerializer`.** `WorldPackSnapshotBuilder` delegates to that
 * class, and the world-pack club snapshot is a frozen cached surface gated by
 * `WORLD_PACK_VERSION`. Re-pricing inside the shared serializer would change cached pack
 * content on every read, with no version bump and no cache invalidation — the exact failure
 * that constant's docblock is a post-mortem of, twice over. Call this at the point a POOL row
 * is serialized for a response, never in the serializer itself.
 *
 * Generated populations do not come through here at all: world-pack NPC squads and a founder's
 * starter squad are both priced once, at generation, where the country and tier are known.
 */
class ServeTimePlayerPricer
{
    public function __construct(private readonly WageModelResolver $wageModel) {}

    /**
     * Returns $row with `contractValue` re-priced, or untouched.
     *
     * Untouched in `legacy` mode, with no country or tier, and for a tier whose turnover nobody
     * has authored — in each case the stored figure stands, which is byte-identical to what
     * every shipped build has always received. That is what lets this ship inert.
     *
     * THE PAYING CLUB'S COUNTRY, NOT THE PLAYER'S NATIONALITY, and that is the decision worth
     * naming. A wage demand is what this club has to pay, so it is anchored to the economy the
     * club plays in; a Brazilian at an English tier-1 club is paid English tier-1 money.
     * Pricing by nationality would make a squad's wage bill depend on where its players were
     * born.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function price(array $row, Player $player, ?string $country, ?int $tier): array
    {
        if ($country === null || $country === '' || $tier === null || $tier < 1) {
            return $row;
        }

        if ($this->wageModel->model() !== EconomyWageModel::TURNOVER) {
            return $row;
        }

        $priced = $this->wageModel->turnoverPlayerPence($player->getCurrentAbility(), $country, $tier);

        if ($priced !== null) {
            $row['contractValue'] = $priced;
        }

        return $row;
    }

    /**
     * A callable for `array_map` that carries the browsing club's context.
     *
     * Controllers map over a result set, so they need the context bound once rather than
     * threaded through every element.
     *
     * @param callable(Player): array<string, mixed> $serialize
     * @return callable(Player): array<string, mixed>
     */
    public function pricedSerializer(callable $serialize, ?string $country, ?int $tier): callable
    {
        return fn (Player $p): array => $this->price($serialize($p), $p, $country, $tier);
    }
}
