<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Service\Economy\ServeTimePlayerPricer;

/**
 * Pool-browsing player shape (GET /api/scout/search, GET /api/players/foreign).
 *
 * This is a thin guardians-on preset over PlayerSnapshotSerializer, which owns the
 * single canonical shape — see that class for why the world-pack snapshot became the
 * model and how the two former shapes were unioned without removing a key.
 *
 * Kept as its own class rather than inlined at the two call sites because it carries a
 * real decision (browsing includes guardians; world-pack generation deliberately does
 * not), and because both controllers already type-hint it.
 *
 * ── AND BECAUSE IT IS WHERE A POOL PLAYER GETS PRICED (2026-11-12) ────────────────────────
 *
 * A pool player is ONE row served to every country at every tier, so `contractValue` cannot be
 * stored under the turnover model — there is no country or tier to price it against at
 * generation time. It is stored at the legacy figure and priced HERE, from the country and tier
 * of the club doing the browsing.
 *
 * THE PRICING LIVES HERE AND NOT IN PlayerSnapshotSerializer, DELIBERATELY. That class is also
 * what `WorldPackSnapshotBuilder` delegates to, and the world-pack club snapshot is a FROZEN
 * CACHED surface gated by `WorldInitializationService::WORLD_PACK_VERSION`. Re-pricing inside
 * the shared serializer would change pack content on every read with no version bump and no
 * cache invalidation — the exact failure that constant's docblock is a post-mortem of, twice
 * over. World-pack players are priced once, at generation, where the country and tier are known.
 *
 * With no country and tier — or in `legacy` mode — the stored value is served untouched, which
 * is byte-identical to what these two endpoints have always returned.
 *
 * **The rule itself now lives in `ServeTimePlayerPricer`**, because it belongs to every surface
 * that serves a pool row rather than to these two endpoints. It used to live here, which is how
 * `GET /api/players/youth` ended up serving the stored legacy figure: it serializes pool players
 * without going through this class, so it never reached the pricing at all.
 */
class PlayerBrowseSerializer
{
    public function __construct(
        private readonly PlayerSnapshotSerializer $snapshots = new PlayerSnapshotSerializer(),
        private readonly ?ServeTimePlayerPricer $pricer = null,
    ) {}

    /**
     * @param string|null $country The BROWSING club's country, not the player's nationality.
     * @param int|null    $tier    The browsing club's tier — what the wage is anchored to.
     */
    public function serialize(Player $p, ?string $country = null, ?int $tier = null): array
    {
        $row = $this->snapshots->serialize($p, withGuardians: true);

        return $this->pricer?->price($row, $p, $country, $tier) ?? $row;
    }

    /**
     * A callable for `array_map` that carries the browsing club's context.
     *
     * The two controllers map over a result set, so they need the context bound once rather
     * than threaded through every element.
     */
    public function serializerFor(?string $country, ?int $tier): callable
    {
        return fn (Player $p): array => $this->serialize($p, $country, $tier);
    }
}
