<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Service\Economy\WageModelResolver;

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
 */
class PlayerBrowseSerializer
{
    public function __construct(
        private readonly PlayerSnapshotSerializer $snapshots = new PlayerSnapshotSerializer(),
        private readonly ?WageModelResolver $wageModel = null,
    ) {}

    /**
     * @param string|null $country The BROWSING club's country, not the player's nationality.
     * @param int|null    $tier    The browsing club's tier — what the wage is anchored to.
     */
    public function serialize(Player $p, ?string $country = null, ?int $tier = null): array
    {
        $row = $this->snapshots->serialize($p, withGuardians: true);

        return $this->withServeTimeWage($row, $p, $country, $tier);
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

    /**
     * THE PAYING CLUB'S COUNTRY, NOT THE PLAYER'S NATIONALITY, and that is the decision worth
     * naming. A wage demand is what this club has to pay, so it is anchored to the economy the
     * club plays in; a Brazilian at an English tier-1 club is paid English tier-1 money. Pricing
     * by nationality would make a squad's wage bill depend on where its players were born.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withServeTimeWage(array $row, Player $p, ?string $country, ?int $tier): array
    {
        if ($this->wageModel === null || $country === null || $country === '' || $tier === null || $tier < 1) {
            return $row;
        }

        $priced = $this->wageModel->turnoverPlayerPence($p->getCurrentAbility(), $country, $tier);

        // Null in `legacy` mode or for a tier whose turnover nobody has authored — the stored
        // figure stands, which is what every shipped build has always received.
        if ($priced !== null && $this->wageModel->model() === \App\Enum\EconomyWageModel::TURNOVER) {
            $row['contractValue'] = $priced;
        }

        return $row;
    }
}
