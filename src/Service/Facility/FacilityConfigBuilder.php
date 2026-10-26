<?php

declare(strict_types=1);

namespace App\Service\Facility;

use App\Entity\Club;
use App\Entity\MatchResult;
use App\Repository\ClubFacilityRepository;

/**
 * Turns a club's ClubFacility rows into the sprite configs the landing page renders.
 *
 * Extracted from ClubSpotlightService rather than copied out of it: the Club Spotlight
 * needs all four sites, and the Owner Spotlight needs the leader's stadium, and a second
 * copy of the level maths would be a second thing to keep in step with the sprite. Both
 * services now depend on this one.
 *
 * Nothing here queries anything but ClubFacility — callers hand in the MatchResults they
 * already loaded, so adding the stadium to a second board costs no extra round trip.
 */
final class FacilityConfigBuilder
{
    /**
     * The stadium's own slugs. Kept here rather than derived from FacilitySpriteMap so
     * the query stays a single indexed lookup on a fixed list.
     */
    private const STADIUM_FACILITY_SLUGS = [
        'north_stand', 'east_stand', 'south_stand', 'west_stand',
        'club_shop', 'museum', 'car_park',
    ];

    public function __construct(
        private readonly ClubFacilityRepository $clubFacilityRepository,
    ) {}

    /**
     * The stadium sprite's config, as composeStadiumSvg() expects it.
     *
     * Everything omitted here falls back to stadium-compositor.js's
     * DEFAULT_STADIUM_CONFIG, which is a *demo* config, not a neutral one — so
     * omission is never "unset", it is "show the demo value". Three groups have
     * to be sent explicitly for that reason:
     *
     *  - shop/museum/carpark are booleans the renderer gates on, separate from
     *    the *Level ints. The defaults are shop:true, carpark:true, museum:false,
     *    so sending levels alone meant a club with no car park still drew a
     *    full two-row one, a club with no shop drew a shop sized off a negative
     *    level, and a maxed-out museum never drew at all.
     *  - seat/roof default to a red-and-white that has nothing to do with the
     *    featured club, on a section whose entire point is the club's identity.
     *    Taken from the club's own home kit when it has one.
     *  - scoreboard defaults to true with home:2, away:1 — a fabricated result.
     *    Only ever shown for a real home fixture, otherwise switched off.
     *
     * @param MatchResult[] $recentResults newest-first, as findRecentByClub() returns
     *
     * @return array<string, int|bool|string>
     */
    public function buildStadium(Club $club, array $recentResults): array
    {
        $facilities = $this->clubFacilityRepository->findBy([
            'club'         => $club,
            'facilitySlug' => self::STADIUM_FACILITY_SLUGS,
        ]);

        $levelBySlug = [];
        foreach ($facilities as $facility) {
            $levelBySlug[$facility->getFacilitySlug()] = $facility->getLevel();
        }

        // FacilityTemplate levels run 1-5; every StadiumConfig level field runs
        // 1-10, so each level is doubled, with a floor of 1 so an unbuilt stand
        // still renders rather than vanishing.
        //
        // The stands always ran 1-10. The shop/museum/car park ran 1-5 in the v1
        // sprite and were passed through raw; v2 redrew all three for 10 levels
        // with size proportional to sqrt(cost), so an un-doubled level 5 shop
        // would now draw at roughly half the size it used to.
        // Clamped as well as doubled now — see spriteLevel()'s own note on why a
        // raw doubling could hand the renderer a level past the end of its table.
        $spriteLevel = static fn (string $slug): int => self::spriteLevel($levelBySlug[$slug] ?? 0);
        $isBuilt     = static fn (string $slug): bool => ($levelBySlug[$slug] ?? 0) > 0;

        $config = [
            'north' => $spriteLevel('north_stand'),
            'east'  => $spriteLevel('east_stand'),
            'south' => $spriteLevel('south_stand'),
            'west'  => $spriteLevel('west_stand'),
            'shopLevel'     => $spriteLevel('club_shop'),
            'museumLevel'   => $spriteLevel('museum'),
            'carparkLevel'  => $spriteLevel('car_park'),
            // The levels are floored at 1 above so the renderer never indexes a
            // size table out of range, which is exactly why these booleans have
            // to carry "not built at all" on their own.
            'shop'          => $isBuilt('club_shop'),
            'museum'        => $isBuilt('museum'),
            'carpark'       => $isBuilt('car_park'),
        ];

        $kit = $club->getHomeKitConfig() ?? [];
        // shade() indexes a 7-char #rrggbb directly, so a 3-digit shorthand or a
        // named colour would render as garbage — leave the default in that case.
        // (homeKitConfig is client-supplied verbatim and never validated server-side.)
        if (self::isHexColor($kit['primary'] ?? null)) {
            $config['seat'] = $kit['primary'];
        }
        if (self::isHexColor($kit['secondary'] ?? null)) {
            $config['roof'] = $kit['secondary'];
        }

        $homeResult = null;
        foreach ($recentResults as $result) {
            if ($result->getIsHome() === true) {
                $homeResult = $result;
                break;
            }
        }

        if ($homeResult !== null) {
            $config['scoreboard'] = true;
            $config['home']       = $homeResult->getGoalsFor();
            $config['away']       = $homeResult->getGoalsAgainst();
        } else {
            $config['scoreboard'] = false;
        }

        return $config;
    }

    /**
     * Ready-to-render sprite configs for all four facility sites.
     *
     * Slugs resolve through FacilitySpriteMap, which is generated from the sprite
     * drop itself, so this never carries its own idea of which slug draws as which
     * id. A slug with no mapping is skipped: `club_facility` stores whatever the
     * client sends, and live data holds five slugs with no FacilityTemplate at all
     * (away_stand, main_stand, kinetic_lab, neuro_shed, zenith_sanctuary), so an
     * unknown slug is an expected input here, not an error.
     *
     * Every facility is emitted with an explicit on/off boolean, because each
     * DEFAULT_*_CONFIG in the drop is the web builder's demo config — omitting a
     * key means "show the demo value", never "unset", and a club with no medical
     * centre would otherwise render someone else's.
     *
     * `*Construction` is always false. `club_facility` is (club, slug, level) and
     * the sync payload's `facilityLevels` is a flat slug => int map: the backend
     * has no notion of an upgrade in progress, so claiming one would be invention.
     *
     * @param MatchResult[] $recentResults
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildAllSites(Club $club, array $recentResults): array
    {
        $levelBySlug = [];
        foreach ($this->clubFacilityRepository->findBy(['club' => $club]) as $facility) {
            $levelBySlug[$facility->getFacilitySlug()] = $facility->getLevel();
        }

        $accent = null;
        $kit    = $club->getHomeKitConfig() ?? [];
        if (self::isHexColor($kit['primary'] ?? null)) {
            $accent = $kit['primary'];
        }

        $configs = [];

        foreach (['training', 'medical', 'scouting'] as $site) {
            $config = [];

            foreach (FacilitySpriteMap::SITES[$site] as $slug => $spriteId) {
                $level = $levelBySlug[$slug] ?? 0;

                $config[$spriteId]                       = $level > 0;
                $config[$spriteId . 'Level']             = self::spriteLevel($level);
                $config[$spriteId . 'Construction']      = false;
            }

            if ($accent !== null) {
                $config['accent'] = $accent;
            }

            $configs[$site] = $config;
        }

        // The stadium's own builder already produces the shape its sprite wants
        // (named stand keys, scoreboard from the last home result), so it is reused
        // rather than rebuilt from the map — one stadium config, two places to read
        // it from.
        $configs['stadium'] = $this->buildStadium($club, $recentResults);

        return $configs;
    }

    /**
     * A FacilityTemplate level (1-5) as the sprite's own level (1-10).
     *
     * Doubling is the mapping the stadium has always used; the three plot sites
     * document the same 1-10 range, so they get the same treatment rather than a
     * second convention.
     *
     * **The upper clamp is not defensive padding.** club_facility.level is written
     * from the client's `facilityLevels` sync payload and is not validated against
     * the template's 1-5 range: live rows reach 11 for training, 10 for medical,
     * 7 for scouting. Doubled, an 11 becomes 22 and indexes a size table that stops
     * at 10. The stadium escapes this today only because its own levels happen to
     * sit at 5 or below — the same bug was always latent there, and routing it
     * through here fixes it rather than leaving one caller exposed.
     *
     * Floor of 1 so an unbuilt facility still renders a plot rather than vanishing;
     * "not built at all" is carried by the explicit on/off boolean beside it,
     * which is why that boolean cannot be inferred from the level.
     */
    private static function spriteLevel(int $templateLevel): int
    {
        return max(1, min(10, $templateLevel * 2));
    }

    private static function isHexColor(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
