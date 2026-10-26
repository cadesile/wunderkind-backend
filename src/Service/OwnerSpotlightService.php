<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Enum\Country;
use App\Repository\ClubRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Facility\FacilityConfigBuilder;
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
 * Owners are ranked on their centralized UserLedger balance — money actually
 * drawn out — not on the summed balance of the clubs they own, and not on
 * Club::$totalCareerEarnings, which is being phased out (see that property's
 * own deprecation note). Only owners who hold ledger entries appear at all;
 * an owner who has never drawn a dividend is absent, not listed at zero.
 *
 * Consequence worth knowing: the ledger records CLIENT-REPORTED `dividend_draw`
 * entries only. The server-side year-end dividend EconomicService computes is
 * deliberately not a ledger entry (explicit product decision), so this board
 * reflects draws the client has actually reported and nothing else — it stays
 * sparse until clients emit them at volume.
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

    /**
     * How many owners the board lists.
     *
     * Seven, not five, for the shape it makes: rank 1 takes the full-width leader
     * card on its own, leaving the remainder to a grid that lays out three across
     * at the section's width. Six fills two complete rows; four left a single
     * orphaned card hanging under the first row.
     */
    public const OWNER_LIMIT = 7;

    /**
     * How many clubs are listed per owner. An owner with more than this still has
     * every club counted in their balance and clubCount — the list is truncated,
     * the ranking is not (production already has an owner with 57 clubs).
     */
    public const CLUBS_PER_OWNER = 5;

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly UserLedgerRepository $userLedgerRepository,
        private readonly FacilityConfigBuilder $facilityConfigBuilder,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * @return array<array{
     *     rank: int, ownerName: string, ownerNationality: ?string, ownerCountryCode: ?string,
     *     ownerAppearance: ?array, drawnPence: int, sharePct: float, clubCount: int,
     *     stadiumConfig: ?array,
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
        $owners = $this->userLedgerRepository->findTopOwnersByBalance(self::OWNER_LIMIT);

        if ($owners === []) {
            return [];
        }

        // One query for every listed owner's clubs, then bucketed in PHP — the
        // alternative (a per-owner query, or a LATERAL per-owner limit) costs one
        // round trip each to save a handful of rows.
        $clubsByOwner = [];
        foreach ($this->clubRepository->findByUserIds(array_column($owners, 'userId')) as $club) {
            $clubsByOwner[(string) $club->getUser()->getId()][] = $club;
        }

        // Share is of the LEADER's total, not of the board's sum: the design shows each
        // owner against the richest, so rank 1 is always 100% and the rest read as "how
        // close is anyone to the top". Computed here rather than in the template because
        // the template cannot see owners it is not rendering.
        $leaderPence = (int) ($owners[0]['balancePence'] ?? 0);

        $result = [];
        foreach ($owners as $i => $owner) {
            // Every non-spoof club the owner holds; the list is truncated for display
            // but clubCount is the real total, so "33 clubs · showing 5" stays honest.
            $allClubs = $clubsByOwner[$owner['userId']] ?? [];

            $result[] = [
                'rank'             => $i + 1,
                // Owner identity is newer than most accounts, so a null name is the
                // common case rather than an edge case — see the Owner Identity
                // section in CLAUDE.md. Matches the Club Spotlight's own fallback.
                'ownerName'        => $owner['ownerName'] ?: 'Anonymous',
                'ownerNationality' => $owner['ownerNationality'],
                'ownerCountryCode' => self::countryCodeForNationality($owner['ownerNationality']),
                'ownerAppearance'  => $owner['ownerAppearance'],
                'drawnPence'       => $owner['balancePence'],
                // A leader on zero makes every share meaningless rather than infinite —
                // the ledger only holds client-reported dividend draws, so an all-zero
                // board is a real state early on, not a divide-by-zero to guard against.
                'sharePct'         => $leaderPence > 0
                    ? round(((int) $owner['balancePence'] / $leaderPence) * 100, 1)
                    : 0.0,
                'clubCount'        => count($allClubs),
                // Rank 1 only: the leader's card is the one the design draws a stadium on,
                // and building it for every listed owner would be six unused ClubFacility
                // queries.
                // Reuses the Club Spotlight's own builder — same levels, same clamping,
                // same explicit on/off — rather than a second copy of that logic.
                'stadiumConfig'    => $i === 0 && $allClubs !== []
                    ? $this->facilityConfigBuilder->buildStadium($allClubs[0], [])
                    : null,
                'clubs'            => array_map(self::serializeClub(...), array_slice($allClubs, 0, self::CLUBS_PER_OWNER)),
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
