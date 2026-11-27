<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The homepage world map: every town where a player owns a plot, with who owns it and
 * which club stands on it (Courtney, 2026-11-27, thread "Add world and country plot maps").
 *
 * One DBAL projection behind a short cache, the OwnerSpotlightService pattern: a handful of
 * joined columns per active claim, so there is nothing to denormalise and no cron to install.
 *
 * WHAT IS PUBLIC. The in-game owner name (User::$name, the same field the Owner Spotlight
 * already shows, 'Anonymous' when unset) and the club name. Never an email, id or balance.
 * Spoof accounts and spoof clubs (competition fillers) are left out.
 *
 * Grouped by TOWN, not by plot: a city can hold several owned plots, and one dot per town is
 * what the map draws. A town that has not been valued has no lat/lng, but a claim needs a
 * priced town, so in practice every claim is placeable; any that is not is skipped.
 */
class OwnedPlotsMapService
{
    public const TTL = 600;

    private const CACHE_KEY = 'owned_plots_map';

    /** Owners listed per town in the hover panel; the count stays the real total. */
    public const OWNERS_PER_TOWN = 8;

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * @return array{
     *     towns: list<array{town: string, region: string, countryCode: string, countryName: string,
     *         lat: float, lng: float, plotCount: int,
     *         owners: list<array{ownerName: string, clubName: ?string}>}>,
     *     totals: array{plots: int, towns: int, countries: int}
     * }
     */
    public function getMap(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);

            return $this->build();
        });
    }

    public function clear(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.id AS location_id, l.town, l.region, l.country_code, l.lat, l.lng,
                    cp.name AS country_name,
                    u.name AS owner_name,
                    COALESCE(c.name, pc.club_name_at_claim) AS club_name,
                    pc.club_id
               FROM plot_claim pc
               JOIN location l ON l.id = pc.location_id
               JOIN "user" u ON u.id = pc.user_id
               LEFT JOIN club c ON c.id = pc.club_id
               LEFT JOIN country_profile cp ON cp.code = l.country_code
              WHERE pc.released_at IS NULL
                AND l.lat IS NOT NULL
                AND l.lng IS NOT NULL
                AND u.email NOT LIKE :spoof
                AND (c.id IS NULL OR c.is_spoof = false)
              ORDER BY l.town ASC, pc.claimed_at ASC',
            ['spoof' => '%' . User::SPOOF_EMAIL_DOMAIN],
        );

        $towns = [];
        foreach ($rows as $row) {
            $key = (string) $row['location_id'];

            $towns[$key] ??= [
                'town'        => (string) $row['town'],
                'region'      => (string) $row['region'],
                'countryCode' => (string) $row['country_code'],
                'countryName' => (string) ($row['country_name'] ?? $row['country_code']),
                'lat'         => round((float) $row['lat'], 4),
                'lng'         => round((float) $row['lng'], 4),
                'plotCount'   => 0,
                'owners'      => [],
            ];

            $towns[$key]['plotCount']++;

            if (count($towns[$key]['owners']) < self::OWNERS_PER_TOWN) {
                $towns[$key]['owners'][] = [
                    'ownerName' => trim((string) $row['owner_name']) !== '' ? (string) $row['owner_name'] : 'Anonymous',
                    // Null for a plot that is owned but empty (club liquidated, not restarted yet).
                    'clubName'  => $row['club_id'] === null ? null : ($row['club_name'] !== null ? (string) $row['club_name'] : null),
                ];
            }
        }

        $towns = array_values($towns);

        return [
            'towns'  => $towns,
            'totals' => [
                'plots'     => count($rows),
                'towns'     => count($towns),
                'countries' => count(array_unique(array_column($towns, 'countryCode'))),
            ],
        ];
    }
}
