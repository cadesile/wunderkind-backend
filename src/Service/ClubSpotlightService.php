<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubSpotlight;
use App\Entity\MatchResult;
use App\Entity\Transfer;
use App\Enum\TransferType;
use App\Repository\ClubFacilityRepository;
use App\Service\Facility\FacilitySpriteMap;
use App\Repository\ClubSpotlightRepository;
use App\Repository\LeagueRepository;
use App\Repository\MatchResultRepository;
use App\Repository\PlayerCareerStatRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\SyncRecordRepository;
use App\Repository\TransferRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Picks the landing page's "Club Spotlight" every 12h: of the clubs with at
 * least MIN_SYNC_COUNT valid syncs in the trailing 12h window, the 5 most
 * active, one chosen at random, snapshot denormalized onto ClubSpotlight so
 * the landing page never queries Club at render time (same reasoning as
 * LiveTelemetryService/LiveTelemetrySnapshot). Notifies the selected club's
 * owner via in-game inbox + push.
 *
 * The eligibility floor means the candidate pool is routinely smaller than
 * CANDIDATE_POOL_SIZE and is often empty on a quiet or fresh environment —
 * that is the normal case, not an error. selectNew() returns null and leaves
 * whatever spotlight is already up in place rather than blanking it.
 */
class ClubSpotlightService
{
    private const ACTIVITY_WINDOW_HOURS = 12;
    private const CANDIDATE_POOL_SIZE   = 5;
    private const SPOTLIGHT_DURATION_HOURS = 12;

    /**
     * Minimum valid syncs inside ACTIVITY_WINDOW_HOURS for a club to be eligible
     * for the spotlight at all — a club that has barely synced isn't "active"
     * enough to be worth featuring. Applied as a HAVING floor in
     * SyncRecordRepository::findMostActiveClubs().
     */
    private const MIN_SYNC_COUNT = 10;

    /** ClubFacility slugs that drive the stadium render's stand/building levels. */
    private const STADIUM_FACILITY_SLUGS = [
        'north_stand', 'east_stand', 'south_stand', 'west_stand',
        'club_shop', 'museum', 'car_park',
    ];

    private const RECENT_FIXTURES_LIMIT  = 5;
    private const RECENT_TRANSFERS_LIMIT = 5;

    public function __construct(
        private readonly SyncRecordRepository $syncRecordRepository,
        private readonly ClubFacilityRepository $clubFacilityRepository,
        private readonly ClubSpotlightRepository $clubSpotlightRepository,
        private readonly MatchResultRepository $matchResultRepository,
        private readonly TransferRepository $transferRepository,
        private readonly PlayerCareerStatRepository $playerCareerStatRepository,
        private readonly UserLedgerRepository $userLedgerRepository,
        private readonly SeasonRecordRepository $seasonRecordRepository,
        private readonly LeagueRepository $leagueRepository,
        private readonly InboxService $inboxService,
        private readonly PushNotificationService $pushNotificationService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getCurrent(): ClubSpotlight
    {
        return $this->clubSpotlightRepository->getCurrent();
    }

    /**
     * Selects a new spotlight and notifies its owner. Three outcomes, and only
     * the first is a change:
     *
     *  - a new club is featured    -> returns that Club, owner notified
     *  - only the incumbent qualified -> returns null, window re-upped, nobody notified
     *  - nothing qualified         -> returns null, row untouched; it falls out
     *                                 of its window and the section stops rendering
     *
     * None of those is a failure. A pool smaller than CANDIDATE_POOL_SIZE is
     * normal too — the random pick just draws from however many qualified.
     */
    public function selectNew(): ?Club
    {
        $since = new \DateTimeImmutable(sprintf('-%d hours', self::ACTIVITY_WINDOW_HOURS));
        $candidates = $this->syncRecordRepository->findMostActiveClubs(
            $since,
            self::CANDIDATE_POOL_SIZE,
            self::MIN_SYNC_COUNT,
        );

        if ($candidates === []) {
            return null;
        }

        $spotlight      = $this->clubSpotlightRepository->getCurrent();
        $windowStartsAt = new \DateTimeImmutable();
        $windowEndsAt   = $windowStartsAt->modify(sprintf('+%d hours', self::SPOTLIGHT_DURATION_HOURS));

        // Don't re-feature whoever is already up: notifyOwner() fires an inbox
        // message and a push on every selection, so re-picking the incumbent
        // spams one owner "You're in the Spotlight!" every cycle for a feature
        // that never visibly changed. With MIN_SYNC_COUNT set high the eligible
        // pool is routinely 1-2 clubs, which makes that the likely case rather
        // than a rare one.
        //
        // Matched on the denormalized clubId, not the name: club names are not
        // unique (production has six "London FC"), so a name match would treat
        // a different club as the incumbent and make it wait a cycle. Rows
        // written before clubId existed have none, so those fall back to the
        // name — a one-off that self-heals the first time this writes a row.
        $incumbentId = $spotlight->getClubId();

        $isIncumbent = $incumbentId !== null
            ? static fn (Club $c): bool => (string) $c->getId() === $incumbentId
            : static fn (Club $c): bool => $c->getName() === $spotlight->getClubName();

        $withoutIncumbent = array_values(array_filter(
            $candidates,
            static fn (Club $c): bool => !$isIncumbent($c),
        ));

        // The incumbent is the only club that qualified. Keep them featured —
        // they are genuinely still the most active club — but only re-up the
        // window, so the section stays live without a duplicate notification.
        if ($withoutIncumbent === []) {
            $spotlight->extendWindow($windowEndsAt);
            $this->em->flush();

            return null;
        }

        $club = $withoutIncumbent[array_rand($withoutIncumbent)];

        $owner        = $club->getUser();
        $recentResults = $this->matchResultRepository->findRecentByClub($club, self::RECENT_FIXTURES_LIMIT);
        $topPerformer = $this->playerCareerStatRepository->findTopPerformerForClub($club);

        $spotlight->update(
            clubName: $club->getName(),
            clubId: (string) $club->getId(),
            clubReputation: $club->getReputation(),
            dividendDrawsPence: $this->userLedgerRepository->getTotalDividendsByClub($club),
            homeKitConfig: $club->getHomeKitConfig(),
            awayKitConfig: $club->getAwayKitConfig(),
            badgeConfig: $club->getBadgeConfig(),
            ownerName: $owner->getName(),
            ownerNationality: $owner->getNationality(),
            ownerAppearance: $owner->getAppearance(),
            stadiumConfig: $this->buildStadiumConfig($club, $recentResults),
            facilities: $this->buildFacilityConfigs($club, $recentResults),
            recentFixtures: $this->buildRecentFixtures($recentResults),
            recentTransfers: $this->buildRecentTransfers($club),
            topPerformerName: $topPerformer?->getPlayerName(),
            topPerformerGoals: $topPerformer?->getGoals() ?? 0,
            topPerformerAssists: $topPerformer?->getAssists() ?? 0,
            topPerformerAppearanceConfig: $topPerformer?->toFullAppearanceConfig(),
            trophies: $this->buildTrophies($club),
            establishedAt: $club->getCreatedAt(),
            windowStartsAt: $windowStartsAt,
            windowEndsAt: $windowEndsAt,
        );

        $this->em->flush();

        $this->notifyOwner($club);

        return $club;
    }

    /**
     * The club's silverware, one entry per tier won, top division first.
     *
     * A "title" is a SeasonRecord with finalPosition = 1 — the same definition the
     * hall_of_fame leaderboard uses, via the same repository method, so the two can
     * never disagree about what counts as a trophy.
     *
     * The trophy is looked up per tier against the club's OWN country, not stored on
     * the title: League::$trophy is admin-editable per (country, tier), so a club's
     * honours board always reflects the trophy that division awards today. A club
     * with no country set, or a tier with no League row for that country, still
     * lists the title — with a null config, which the template renders as a
     * count-only chip. Dropping it would under-report real silverware because of a
     * missing admin setting.
     *
     * @return array<int, array{tier: int, titles: int, trophy: ?array, leagueName: ?string}>
     */
    private function buildTrophies(Club $club): array
    {
        $rows    = $this->seasonRecordRepository->countTitlesByClubAndTier($club);
        $country = $club->getCountry();

        $trophies = [];
        foreach ($rows as $row) {
            $league = $country !== null
                ? $this->leagueRepository->findByCountryAndTier($country, $row['tier'])
                : null;

            $trophies[] = [
                'tier'         => $row['tier'],
                'titles'       => $row['titles'],
                'trophy'       => $league?->getTrophy(),
                'leagueName'   => $league?->getName(),
            ];
        }

        // Tier 1 is the TOP division and 8 is the bottom (see CLAUDE.md's
        // Server-Driven Messaging note on inverted tiers), so ascending tier
        // number is descending prestige — the order an honours board wants.
        usort($trophies, static fn (array $a, array $b): int => $a['tier'] <=> $b['tier']);

        return $trophies;
    }

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
    private function buildStadiumConfig(Club $club, array $recentResults): array
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
    private function buildFacilityConfigs(Club $club, array $recentResults): array
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
        $configs['stadium'] = $this->buildStadiumConfig($club, $recentResults);

        return $configs;
    }

    private static function isHexColor(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * @param MatchResult[] $results newest-first, as findRecentByClub() returns
     *
     * @return array<int, array{opponent: string, scoreFor: int, scoreAgainst: int, week: int, result: string}>
     */
    private function buildRecentFixtures(array $results): array
    {
        return array_map(static function (MatchResult $m): array {
            $result = match (true) {
                $m->getGoalsFor() > $m->getGoalsAgainst() => 'W',
                $m->getGoalsFor() < $m->getGoalsAgainst() => 'L',
                default => 'D',
            };

            return [
                'opponent'     => $m->getOpponentClubName() ?? 'Unknown',
                'scoreFor'     => $m->getGoalsFor(),
                'scoreAgainst' => $m->getGoalsAgainst(),
                'week'         => $m->getWeek(),
                'result'       => $result,
            ];
        }, $results);
    }

    /**
     * @return array<int, array{playerName: string, feePence: int, direction: string, counterpartyClub: string}>
     */
    private function buildRecentTransfers(Club $club): array
    {
        $transfers = $this->transferRepository->findByClub($club, self::RECENT_TRANSFERS_LIMIT);

        return array_map(static function (Transfer $t): array {
            $incoming = $t->getType() === TransferType::SIGNING;

            return [
                'playerName'       => $t->getPlayerName() ?? 'Unknown Player',
                'feePence'         => $t->getFee(),
                'direction'        => $incoming ? 'in' : 'out',
                'counterpartyClub' => $incoming
                    ? ($t->getClubLeaving() ?? 'Free Agent')
                    : $t->getDestinationClubName(),
            ];
        }, $transfers);
    }

    private function notifyOwner(Club $club): void
    {
        $title = "You're in the Spotlight!";
        $body  = sprintf(
            '%s has been featured on the Build My Club homepage for the next %d hours — the owner community is watching.',
            $club->getName(),
            self::SPOTLIGHT_DURATION_HOURS,
        );

        $this->inboxService->sendSystemNotification($club, $title, $body);

        $owner = $club->getUser();
        $this->pushNotificationService->notifyUsers(
            [(string) $owner->getId()],
            $title,
            $body,
            ['type' => 'club_spotlight'],
        );
    }
}
