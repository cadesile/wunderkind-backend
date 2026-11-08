<?php

declare(strict_types=1);

namespace App\Service\Location;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Puts NPC clubs on specific land plots.
 *
 * WHAT THIS MAKES TRUE. LocationCapacityService has always subtracted NPC clubs from a town's
 * available plots — `min(npcClubs, NPC_PLOT_CAP)` — but it was a COUNT. Nothing said which
 * plot, so the catalogue could report two plots taken by clubs while a player bought plot 1,
 * and a world pack had no plot number to give the club standing there. Seating writes the link
 * that rule always implied.
 *
 * IDEMPOTENT, AND THE ONLY WAY TO RUN IT. Every path — generation, the backfill command, a
 * re-run after enrichment prices a town — calls seatCountry(). Running it twice seats nothing
 * new, because a club that already has a plot is skipped and a plot that already has a club is
 * not offered.
 *
 * ONE STATEMENT PER COUNTRY, not one per club. A country is ~144 clubs across ~40 towns and
 * this runs at the end of every generation; a per-club round trip would make it the slowest
 * part of the operation for no benefit.
 *
 * WHICH CLUB GETS WHICH PLOT. Lowest tier number first — tier 1 is the top division — then by
 * id so the answer is stable across runs. Plots go lowest plot_number first. So a town's best
 * club sits on plot 1, which is the order an operator reading the two lists side by side
 * expects, and re-running after a reap gives the same answer rather than reshuffling.
 *
 * WHAT IS NOT OFFERED: a plot that is retired, already seated, under a live claim, or under a
 * live hold. The hold matters — somebody is at the checkout for it, and seating a club there
 * would turn their purchase into a 409 at the last step.
 */
final class NpcClubPlotSeatingService
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Seat every unseated club in one country, up to the per-town cap.
     *
     * @return array{seated: int, towns: int}
     */
    public function seatCountry(string $countryCode): array
    {
        $code = strtoupper(trim($countryCode));

        if ($code === '') {
            return ['seated' => 0, 'towns' => 0];
        }

        // ONE UPDATE, driven by a pair of windowed rankings.
        //
        // `club_rank` numbers a town's unseated clubs in the order they should be served, and
        // `plot_rank` numbers its free plots. Joining the two on rank pairs them off, and the
        // cap is applied by subtracting the seats a town has ALREADY given out — so a town with
        // one seated club offers one more, not two.
        $seated = (int) $this->connection->executeStatement(
            <<<'SQL'
            WITH taken AS (
                SELECT n.location_id, COUNT(*) AS already
                  FROM npc_club n
                 WHERE n.land_plot_id IS NOT NULL
                 GROUP BY n.location_id
            ),
            free_plots AS (
                SELECT p.id,
                       p.location_id,
                       ROW_NUMBER() OVER (PARTITION BY p.location_id ORDER BY p.plot_number ASC) AS plot_rank
                  FROM land_plot p
                 WHERE p.status = 'active'
                   AND NOT EXISTS (SELECT 1 FROM npc_club nc WHERE nc.land_plot_id = p.id)
                   AND NOT EXISTS (
                        SELECT 1 FROM plot_claim pc
                         WHERE pc.plot_id = p.id AND pc.released_at IS NULL
                   )
                   AND NOT EXISTS (
                        SELECT 1 FROM plot_hold ph
                         WHERE ph.plot_id = p.id
                           AND ph.released_at IS NULL AND ph.consumed_at IS NULL
                           AND ph.expires_at > NOW()
                   )
            ),
            seatable AS (
                SELECT c.id AS club_id,
                       c.location_id,
                       ROW_NUMBER() OVER (PARTITION BY c.location_id ORDER BY c.tier ASC, c.id ASC) AS club_rank
                  FROM npc_club c
                  JOIN location l ON l.id = c.location_id
                 WHERE c.land_plot_id IS NULL
                   AND l.country_code = :code
            )
            UPDATE npc_club
               SET land_plot_id = pairing.plot_id
              FROM (
                    SELECT s.club_id, f.id AS plot_id
                      FROM seatable s
                      JOIN free_plots f
                        ON f.location_id = s.location_id
                       AND f.plot_rank   = s.club_rank
                      LEFT JOIN taken t ON t.location_id = s.location_id
                     WHERE s.club_rank <= :cap - COALESCE(t.already, 0)
              ) AS pairing
             WHERE npc_club.id = pairing.club_id
            SQL,
            ['code' => $code, 'cap' => LocationCapacityService::NPC_PLOT_CAP],
        );

        $towns = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT c.location_id)
               FROM npc_club c
               JOIN location l ON l.id = c.location_id
              WHERE c.land_plot_id IS NOT NULL AND l.country_code = :code',
            ['code' => $code],
        );

        return ['seated' => $seated, 'towns' => $towns];
    }

    /**
     * Release the seats of clubs that no longer have a plot to sit on.
     *
     * NOT NEEDED FOR DELETED PLOTS — the FK is ON DELETE SET NULL, so a reaped plot clears its
     * own seats. This is for a plot that was RETIRED rather than deleted: the row survives with
     * status 'retired', the FK does not fire, and a club would keep pointing at ground that is
     * no longer part of the town.
     *
     * @return int seats released
     */
    public function releaseRetiredSeats(string $countryCode): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE npc_club
                SET land_plot_id = NULL
               FROM land_plot p, location l
              WHERE p.id = npc_club.land_plot_id
                AND l.id = npc_club.location_id
                AND l.country_code = :code
                AND p.status <> 'active'",
            ['code' => strtoupper(trim($countryCode))],
        );
    }

    /**
     * Every country that has at least one club without a seat, so a sweep can skip the rest.
     *
     * @return list<string>
     */
    public function countriesNeedingSeats(): array
    {
        return array_map(
            static fn ($row): string => (string) $row,
            $this->connection->fetchFirstColumn(
                'SELECT DISTINCT l.country_code
                   FROM npc_club c
                   JOIN location l ON l.id = c.location_id
                  WHERE c.land_plot_id IS NULL
                  ORDER BY l.country_code',
            ),
        );
    }
}
