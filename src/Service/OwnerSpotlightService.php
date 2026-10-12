<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Enum\Country;
use App\Repository\ClubRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The landing page's "Owner Spotlight": the richest owners in the game, each with
 * the clubs they own and the division each club currently sits in.
 *
 * Deliberately NOT modelled like ClubSpotlight/LiveTelemetrySnapshot. Those two
 * denormalize onto a singleton row because they aggregate over sync_record
 * payloads, which is far too expensive to do per request. This is two indexed
 * queries over club/user, so it is computed live behind the same short-lived
 * cache WorldOverviewService uses — no entity, no migration, no cron entry to
 * forget to install, and the board is never stale by up to half a cycle.
 *
 * Owners are ranked on summed club balance rather than the centralized
 * UserLedger — see ClubRepository::findRichestOwners() for why.
 */
class OwnerSpotlightService
{
    /**
     * Short enough that a big transfer shows up on the board the same visit it
     * happened, long enough that a burst of traffic doesn't re-run the ranking
     * for every visitor.
     */
    public const TTL = 600;

    private const CACHE_KEY = 'owner_spotlight';

    /** How many owners the board lists. */
    public const OWNER_LIMIT = 5;

    /**
     * How many clubs are listed per owner. An owner with more than this still has
     * every club counted in their balance and clubCount — the list is truncated,
     * the ranking is not (production already has an owner with 57 clubs).
     */
    public const CLUBS_PER_OWNER = 5;

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * @return array<array{
     *     rank: int, ownerName: string, ownerNationality: ?string, ownerCountryCode: ?string,
     *     ownerAppearance: ?array, balancePence: int, clubCount: int,
     *     clubs: array<array{name: string, division: string, tier: ?int, countryCode: ?string,
     *         countryLabel: ?string, homeKitConfig: ?array, awayKitConfig: ?array, badgeConfig: ?array}>
     * }>
     */
    public function getTopOwners(): array
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

    /** @return array<array<string, mixed>> */
    private function build(): array
    {
        $owners = $this->clubRepository->findRichestOwners(self::OWNER_LIMIT);

        if ($owners === []) {
            return [];
        }

        // One query for every listed owner's clubs, then bucketed in PHP — the
        // alternative (a per-owner query, or a LATERAL top-5) costs five round
        // trips to save a handful of rows.
        $clubsByOwner = [];
        foreach ($this->clubRepository->findByUserIds(array_column($owners, 'userId')) as $club) {
            $clubsByOwner[(string) $club->getUser()->getId()][] = $club;
        }

        $result = [];
        foreach ($owners as $i => $owner) {
            $clubs = array_slice($clubsByOwner[$owner['userId']] ?? [], 0, self::CLUBS_PER_OWNER);

            $result[] = [
                'rank'             => $i + 1,
                // Owner identity is newer than most accounts, so a null name is the
                // common case rather than an edge case — see the Owner Identity
                // section in CLAUDE.md. Matches the Club Spotlight's own fallback.
                'ownerName'        => $owner['ownerName'] ?: 'Anonymous',
                'ownerNationality' => $owner['ownerNationality'],
                'ownerCountryCode' => self::countryCodeForNationality($owner['ownerNationality']),
                'ownerAppearance'  => $owner['ownerAppearance'],
                'balancePence'     => $owner['balance'],
                'clubCount'        => $owner['clubCount'],
                'clubs'            => array_map(self::serializeClub(...), $clubs),
            ];
        }

        return $result;
    }

    /**
     * @return array{name: string, division: string, tier: ?int, countryCode: ?string,
     *     countryLabel: ?string, homeKitConfig: ?array, awayKitConfig: ?array, badgeConfig: ?array}
     */
    private static function serializeClub(Club $club): array
    {
        $league = $club->getCurrentLeague();
        $code   = $club->getCountry();

        return [
            'name' => $club->getName(),
            // A club that has never been placed in a league has no division to show.
            // "Unplaced" is honest; a blank cell reads as a rendering bug.
            'division'      => $league?->getName() ?? 'Unplaced',
            'tier'          => $league?->getTier(),
            'countryCode'   => $code,
            'countryLabel'  => $code !== null ? (Country::tryFrom($code)?->label()) : null,
            'homeKitConfig' => $club->getHomeKitConfig(),
            'awayKitConfig' => $club->getAwayKitConfig(),
            'badgeConfig'   => $club->getBadgeConfig(),
        ];
    }

    /**
     * User::$nationality holds a demonym ("English"), not a code — the same
     * convention Player/Staff use. Country::fromNationality() is the single
     * mapping (see CLAUDE.md's World Generation section); never hand-roll another.
     */
    private static function countryCodeForNationality(?string $nationality): ?string
    {
        if ($nationality === null || $nationality === '') {
            return null;
        }

        return Country::fromNationality($nationality)?->value;
    }
}
