<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Enum\CitySize;
use App\Exception\Location\PlotUnavailableException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * How many clubs a town can hold, how many of those are spoken for, and whether any are left.
 *
 * THE MODEL. A town has a number of plots scaled to its size; NPC clubs consume some of them up
 * to a cap; players buy what remains. A town is SOLD OUT when nothing remains. That is the whole
 * rule — there is no separate "this town is reserved" flag, because a capacity model answers the
 * same question without a special case, and it answers it the same way for NPC clubs and
 * players alike.
 *
 * IT REPLACED A TIER RULE. An earlier cut blocked any town with a top-flight club. That worked
 * but was arbitrary: it could not express "this city is big enough for three clubs", and it made
 * availability a property of who happened to be generated there rather than of the town itself.
 *
 * WHY NPC CLUBS ARE CAPPED. Generation puts ~144 clubs per country into a 40-town list, so a
 * town can easily have eight or ten. Letting every one of them consume a plot would make nearly
 * every town sold out no matter how many plots it had — the cap is what keeps the plot count,
 * and therefore enrichment, the thing that actually decides availability.
 *
 * NPC OCCUPANCY IS A COUNT, NOT SPECIFIC PLOTS. NPC clubs carry a location, not a claim, so
 * there is no plot row to point at; they reduce how many of a town's plots can be sold rather
 * than reserving particular ones. A player claiming a plot in a town with capacity left is
 * claiming a real `land_plot` row as before.
 *
 * EVERY NUMBER HERE IS A DIAL. They are constants rather than config because they are balance,
 * not deployment — but they are expected to be tuned against real distributions, so they are
 * gathered here and nowhere else.
 */
final class LocationCapacityService
{
    /**
     * Plots a town has, by size.
     *
     * Derived from city size, which is derived from population, which enrichment now supplies —
     * so this is the chain that makes "enrichment determines the number of plots" true.
     */
    public const PLOTS_BY_CITY_SIZE = [
        CitySize::BIG->value    => 4,
        CitySize::MEDIUM->value => 2,
        CitySize::SMALL->value  => 1,
    ];

    /**
     * The most plots NPC clubs can take in any one town, however many of them there are.
     *
     * Two. A town with ten generated clubs still only loses two plots to them, so a big town
     * stays partly open while a one-plot village is full the moment anyone is generated there.
     */
    public const NPC_PLOT_CAP = 2;

    /** The town has no plots because it has never been priced — ask for enrichment and wait. */
    public const REASON_NOT_PRICED = 'awaiting_enrichment';

    /**
     * The town has plots and every one of them is taken — look elsewhere.
     *
     * Aliased to the EXCEPTION's constant rather than repeating the literal, because the two
     * must be the same string: a client reads this off the catalogue to decide whether to offer
     * the buy button, and reads the other off a `409` when it offers it anyway. Two literals
     * that happen to agree today is how they stop agreeing later.
     */
    public const REASON_SOLD_OUT = PlotUnavailableException::TOWN_SOLD_OUT;

    public function __construct(private readonly Connection $connection) {}

    /** Plots a town of this size should have. */
    public static function plotsFor(CitySize $citySize): int
    {
        return self::PLOTS_BY_CITY_SIZE[$citySize->value] ?? 1;
    }

    /**
     * Capacity for a page of towns, in one query.
     *
     * Batched because the list endpoint needs it per row; asking per town would be fifty queries
     * for a fifty-row page.
     *
     * @param list<string> $locationIds
     *
     * @return array<string, array{plots: int, npcClubs: int, npcOccupied: int, claimed: int,
     *     available: int, soldOut: bool, unprovisioned: bool, reason: ?string}>
     */
    public function forLocations(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.id,
                    COUNT(DISTINCT p.id) FILTER (WHERE p.status = :activePlot) AS plots,
                    COUNT(DISTINCT n.id) AS npc_clubs,
                    COUNT(DISTINCT pc.id) FILTER (WHERE pc.released_at IS NULL) AS claimed
               FROM location l
               LEFT JOIN land_plot p  ON p.location_id = l.id
               LEFT JOIN npc_club  n  ON n.location_id = l.id
               LEFT JOIN plot_claim pc ON pc.location_id = l.id
              WHERE l.id IN (:ids)
              GROUP BY l.id',
            ['ids' => $locationIds, 'activePlot' => 'active'],
            ['ids' => ArrayParameterType::STRING],
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['id']] = $this->shape(
                (int) $row['plots'],
                (int) $row['npc_clubs'],
                (int) $row['claimed'],
            );
        }

        return $out;
    }

    /**
     * @return array{plots: int, npcClubs: int, npcOccupied: int, claimed: int, available: int,
     *     soldOut: bool, unprovisioned: bool, reason: ?string}
     */
    public function forLocation(string $locationId): array
    {
        return $this->forLocations([$locationId])[$locationId] ?? $this->shape(0, 0, 0);
    }

    /**
     * @return array{plots: int, npcClubs: int, npcOccupied: int, claimed: int, available: int,
     *     soldOut: bool, unprovisioned: bool, reason: ?string}
     */
    private function shape(int $plots, int $npcClubs, int $claimed): array
    {
        $npcOccupied = min($npcClubs, self::NPC_PLOT_CAP);

        // Floored at zero: a town can legitimately be over-subscribed — three NPC clubs in a
        // one-plot village, or a town that re-banded DOWN after its plots were already claimed.
        // Negative availability is not a thing a caller should have to reason about.
        $available = max(0, $plots - $npcOccupied - $claimed);

        // NO PLOTS IS NOT THE SAME AS NO PLOTS LEFT, and conflating them sends a client the
        // wrong way. A town with zero plots has never been sized — it was imported as a bare
        // name and nobody has asked for it to be priced, so the answer is "ask for enrichment
        // and wait". A town whose plots are all taken is finished, and the answer is "look
        // elsewhere". Both used to report town_sold_out.
        $unprovisioned = $plots === 0;

        return [
            'plots'         => $plots,
            'npcClubs'      => $npcClubs,
            'npcOccupied'   => $npcOccupied,
            'claimed'       => $claimed,
            'available'     => $available,
            'soldOut'       => $available === 0,
            'unprovisioned' => $unprovisioned,
            'reason'        => match (true) {
                $available > 0 => null,
                $unprovisioned => self::REASON_NOT_PRICED,
                default        => self::REASON_SOLD_OUT,
            },
        ];
    }
}
