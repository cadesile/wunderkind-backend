<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\NpcClub;
use App\Repository\NpcClubRepository;

/**
 * The land under every NPC club in a country, WITH ITS MONEY — for the device to store on each
 * NPC club (owner, 2026-11-26: "All plot data for NPC clubs is required for club valuation when
 * determining investor valuation", and it must be on device so it works offline).
 *
 * ── WHY THIS IS NOT IN THE WORLD PACK ────────────────────────────────────────────────────
 *
 * A pack is a census (WorldPackSnapshotBuilder, owner 2026-11-18): it says WHICH ground a club
 * stands on (`land`), never what it is worth, because a price baked into a cached pack turns
 * every land re-authoring into a WORLD_PACK_VERSION bump. Land prices and plot capacity are
 * operator-authored and change as players claim, so they ride this UNCACHED endpoint instead,
 * exactly like `/api/initialize/leagues` carries the league payouts. The device calls it once
 * when a world is built (and once for a save built before it existed) and keeps the answer.
 *
 * Census and money are both here so the device needs one call: the location and plot ids
 * (which the pack does not carry), the town's plot counts, and the prices.
 */
class NpcClubLandService
{
    public function __construct(
        private readonly NpcClubRepository $npcClubs,
        private readonly LocationCapacityService $capacity,
    ) {}

    /**
     * @return list<array{clubId: string, locationId: string, town: string, region: string,
     *     plotId: ?string, plotNumber: ?int, plotLandPence: ?int, townLandPence: ?int,
     *     population: ?int, capital: bool, citySize: string, enrichmentStatus: string,
     *     plots: int, npcClubs: int, npcOccupied: int, claimed: int, available: int}>
     */
    public function forCountry(string $country): array
    {
        $clubs = array_values(array_filter(
            $this->npcClubs->findByCountryWithLand($country),
            static fn(NpcClub $c) => $c->getLocation() !== null,
        ));

        $locationIds = array_values(array_unique(array_map(
            static fn(NpcClub $c) => (string) $c->getLocation()->getId(),
            $clubs,
        )));
        $capacity = $this->capacity->forLocations($locationIds);

        return array_map(fn(NpcClub $c) => $this->row($c, $capacity[(string) $c->getLocation()->getId()] ?? null), $clubs);
    }

    /**
     * @param array{plots: int, npcClubs: int, npcOccupied: int, claimed: int, available: int}|null $capacity
     */
    public function row(NpcClub $club, ?array $capacity): array
    {
        $location = $club->getLocation();
        $plot     = $club->getLandPlot();

        return [
            'clubId'           => (string) $club->getId(),
            'locationId'       => (string) $location->getId(),
            'town'             => $location->getTown(),
            'region'           => $location->getRegion(),
            'plotId'           => $plot !== null ? (string) $plot->getId() : null,
            'plotNumber'       => $plot?->getPlotNumber(),
            // The plot's own figure, else the town's — what a buyer would be charged for it.
            // Null for an unseated club (no plot of its own) and for a town not yet valued.
            'plotLandPence'    => $plot?->getEffectiveLandPence(),
            'townLandPence'    => $location->getLandPence(),
            'population'       => $location->getPopulation(),
            'capital'          => $location->isCapital(),
            'citySize'         => $location->getCitySize()->value,
            'enrichmentStatus' => $location->getEnrichmentStatus()->value,
            'plots'            => $capacity['plots'] ?? 0,
            'npcClubs'         => $capacity['npcClubs'] ?? 0,
            'npcOccupied'      => $capacity['npcOccupied'] ?? 0,
            'claimed'          => $capacity['claimed'] ?? 0,
            'available'        => $capacity['available'] ?? 0,
        ];
    }
}
