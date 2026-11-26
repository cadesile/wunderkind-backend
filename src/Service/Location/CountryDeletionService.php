<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Enum\Location\CountryProfileStatus;
use Doctrine\DBAL\Connection;

/**
 * Deletes a country and everything the world generator built for it, from the Countries admin.
 *
 * WHY THIS EXISTS. Deleting a country_profile straight from EasyAdmin hit
 * location.country_code's ON DELETE RESTRICT (fk_5e9e89cbf026bb7c) and showed a raw 500. That
 * RESTRICT is deliberate: towns carry plots, and plots carry players' real money. But a
 * country that was seeded or bootstrapped by mistake, and never played in, has no such money
 * attached, and Retired would only hide rows nobody wants. So the delete is allowed, but ONLY
 * when nothing a player owns depends on the country, and every such case is refused instead.
 *
 * MOST LINKS ARE BY CODE, NOT BY FOREIGN KEY. Only `location` has a real FK to country_profile.
 * Leagues, NPC clubs, world pack sets/runs/caches, season snapshots and enrichment runs store
 * the code as a bare string, so a plain delete would have left them all orphaned. They are
 * listed here explicitly, and removed in FK order inside one transaction.
 *
 * WHAT BLOCKS A DELETE (player data, never removed here):
 *  - user clubs in the country, homed in one of its towns, or currently in one of its leagues;
 *  - season records or season snapshots (a club's history);
 *  - plots taken by a player, and released claims (real money; the ledger points at them).
 *    Towns themselves never block, enriched or not (Courtney, 2026-11-26);
 *  - open plot holds (a player is buying land there right now);
 *  - world pack sets claimed by a club (that world lives on the player's device);
 *  - leaderboard archive entries for the country.
 *
 * ALSO REFUSED: the economy baseline country (every other country is a ratio of it), and a
 * Live country (players can pick it right now; set it to Retired or Draft first).
 *
 * The market pool's players, staff and scouts are NOT country-owned (a Dutch player can play
 * anywhere), so they are left alone.
 */
final class CountryDeletionService
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * What deleting this country would hit.
     *
     * @return array{
     *     exists: bool,
     *     name: string,
     *     status: string,
     *     refusals: list<string>,
     *     blockers: list<array{label: string, count: int}>,
     *     removes: list<array{label: string, count: int}>,
     *     deletable: bool,
     * }
     */
    public function inventory(string $code): array
    {
        return $this->inventoryOn($this->connection, $code, false);
    }

    /**
     * Delete the country and its generated data, or refuse and say why.
     *
     * @return array{deleted: bool, reasons: list<string>, removed: list<array{label: string, count: int}>}
     */
    public function delete(string $code): array
    {
        return $this->connection->transactional(function (Connection $c) use ($code): array {
            // FOR UPDATE on the profile and its towns, so a claim or a new club cannot land
            // between the check and the delete.
            $inventory = $this->inventoryOn($c, $code, true);

            if (!$inventory['exists']) {
                return ['deleted' => false, 'reasons' => ["country {$code} does not exist"], 'removed' => []];
            }

            if (!$inventory['deletable']) {
                $reasons = $inventory['refusals'];
                foreach ($inventory['blockers'] as $b) {
                    $reasons[] = "{$b['count']} {$b['label']}";
                }

                return ['deleted' => false, 'reasons' => $reasons, 'removed' => []];
            }

            $this->deleteRows($c, $code);

            return ['deleted' => true, 'reasons' => [], 'removed' => $inventory['removes']];
        });
    }

    /** @return array{exists: bool, name: string, status: string, refusals: list<string>, blockers: list<array{label: string, count: int}>, removes: list<array{label: string, count: int}>, deletable: bool} */
    private function inventoryOn(Connection $c, string $code, bool $lock): array
    {
        $code = strtoupper(trim($code));

        $profile = $c->fetchAssociative(
            'SELECT code, name, status, is_economy_baseline FROM country_profile WHERE code = :c' . ($lock ? ' FOR UPDATE' : ''),
            ['c' => $code],
        );

        if ($profile === false) {
            return ['exists' => false, 'name' => $code, 'status' => '', 'refusals' => [], 'blockers' => [], 'removes' => [], 'deletable' => false];
        }

        if ($lock) {
            $c->executeQuery('SELECT id FROM location WHERE country_code = :c FOR UPDATE', ['c' => $code]);
        }

        $refusals = [];

        if ((bool) $profile['is_economy_baseline']) {
            $refusals[] = 'it is the economy baseline country (every other country is scaled from it)';
        }

        if ($profile['status'] === CountryProfileStatus::LIVE->value) {
            $refusals[] = 'it is Live, so players can pick it right now. Set it to Retired or Draft first';
        }

        $p = ['c' => $code];

        $blockers = $this->counts($c, $p, [
            'player club(s) in this country' => 'SELECT COUNT(*) FROM club WHERE country = :c',
            'player club(s) homed in its towns' => 'SELECT COUNT(*) FROM club WHERE home_location_id IN (SELECT id FROM location WHERE country_code = :c)',
            'player club(s) playing in its leagues' => 'SELECT COUNT(*) FROM club WHERE current_league_id IN (SELECT id FROM league WHERE country = :c)',
            'season record(s) in its leagues' => 'SELECT COUNT(*) FROM season_record WHERE league_id IN (SELECT id FROM league WHERE country = :c)',
            'season snapshot(s)' => 'SELECT COUNT(*) FROM season_snapshot WHERE country = :c',
            'plot(s) taken by a player' => 'SELECT COUNT(*) FROM plot_claim WHERE released_at IS NULL AND location_id IN (SELECT id FROM location WHERE country_code = :c)',
            'released plot claim(s), money history the ledger points at' => 'SELECT COUNT(*) FROM plot_claim WHERE released_at IS NOT NULL AND location_id IN (SELECT id FROM location WHERE country_code = :c)',
            'open plot hold(s), a player is buying land there now' => 'SELECT COUNT(*) FROM plot_hold h JOIN land_plot p ON p.id = h.plot_id
                 WHERE p.location_id IN (SELECT id FROM location WHERE country_code = :c)
                   AND h.released_at IS NULL AND h.consumed_at IS NULL AND h.expires_at > NOW()',
            'world pack set(s) claimed by a club' => 'SELECT COUNT(*) FROM world_pack_set WHERE country = :c AND claimed_by_club_id IS NOT NULL',
            'leaderboard archive entr(ies)' => 'SELECT COUNT(*) FROM leaderboard_period_archive_entry WHERE country_code = :c',
        ]);

        $removes = $this->counts($c, $p, [
            'league(s), with their sponsors' => 'SELECT COUNT(*) FROM league WHERE country = :c',
            'NPC club(s)' => 'SELECT COUNT(*) FROM npc_club WHERE country = :c OR league_id IN (SELECT id FROM league WHERE country = :c)',
            'town(s), with stadium estimates, rivals and enrichment attempts' => 'SELECT COUNT(*) FROM location WHERE country_code = :c',
            'land plot(s)' => 'SELECT COUNT(*) FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            'world pack set(s)' => 'SELECT COUNT(*) FROM world_pack_set WHERE country = :c',
            'world pack cache row(s)' => 'SELECT COUNT(*) FROM country_world_pack_cache WHERE country = :c',
            'world pack generation run(s)' => 'SELECT COUNT(*) FROM world_pack_generation_run WHERE country = :c',
            'town suggestion batch(es)' => 'SELECT COUNT(*) FROM town_suggestion_batch WHERE country_code = :c',
            'enrichment run(s)' => 'SELECT COUNT(*) FROM location_enrichment_run WHERE country_code = :c',
        ]);

        return [
            'exists'    => true,
            'name'      => (string) $profile['name'],
            'status'    => (string) $profile['status'],
            'refusals'  => $refusals,
            'blockers'  => $blockers,
            'removes'   => $removes,
            'deletable' => $refusals === [] && $blockers === [],
        ];
    }

    /**
     * @param array<string, string> $p
     * @param array<string, string> $queries label => COUNT(*) query
     *
     * @return list<array{label: string, count: int}> only the non-zero ones
     */
    private function counts(Connection $c, array $p, array $queries): array
    {
        $out = [];

        foreach ($queries as $label => $sql) {
            $n = (int) $c->fetchOne($sql, $p);
            if ($n > 0) {
                $out[] = ['label' => $label, 'count' => $n];
            }
        }

        return $out;
    }

    /** FK order: children before parents. Every blocker above is zero by the time this runs. */
    private function deleteRows(Connection $c, string $code): void
    {
        $p      = ['c' => $code];
        $towns  = 'SELECT id FROM location WHERE country_code = :c';
        $leagues = 'SELECT id FROM league WHERE country = :c';

        // World packs. Runs cascade to their tier and club runs; sets cascade to caches and
        // any tier run still pointing at them. Caches first anyway, explicitly.
        $c->executeStatement('DELETE FROM world_pack_generation_run WHERE country = :c', $p);
        $c->executeStatement('DELETE FROM world_pack_generation_tier_run WHERE country = :c', $p);
        $c->executeStatement('DELETE FROM country_world_pack_cache WHERE country = :c', $p);
        $c->executeStatement('DELETE FROM world_pack_set WHERE country = :c', $p);

        // NPC clubs before leagues: npc_club.league_id has no ON DELETE action.
        $c->executeStatement("DELETE FROM npc_club WHERE country = :c OR league_id IN ({$leagues})", $p);
        // Sponsors and sponsor income cascade from the league.
        $c->executeStatement('DELETE FROM league WHERE country = :c', $p);

        // Towns from another country that named one of these as a rival need recomputing.
        $c->executeStatement(
            "UPDATE location SET rivals_computed_at = NULL
              WHERE country_code <> :c
                AND id IN (SELECT location_id FROM location_rival WHERE rival_location_id IN ({$towns}))",
            $p,
        );

        // Spent or expired holds (open ones blocked above): plot_hold -> stadium_estimate is
        // RESTRICT and would fight the location's own cascade, so they go first.
        $c->executeStatement("DELETE FROM plot_hold WHERE plot_id IN (SELECT id FROM land_plot WHERE location_id IN ({$towns}))", $p);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN ({$towns})", $p);
        // Enrichment attempts, rivals (both directions) and stadium estimates cascade.
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', $p);

        // Batches cascade from their run, but a batch can outlive it; take both.
        $c->executeStatement('DELETE FROM town_suggestion_batch WHERE country_code = :c', $p);
        $c->executeStatement('DELETE FROM location_enrichment_run WHERE country_code = :c', $p);

        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', $p);
    }
}
