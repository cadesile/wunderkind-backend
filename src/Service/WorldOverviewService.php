<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Country;
use App\Enum\LeaderboardCategory;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\StarterConfigRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The public shape of the game world: which countries are playable, what the
 * league pyramid looks like, and what a new chairman starts with.
 *
 * This exists so the marketing site stops hard-coding figures. The landing page
 * previously claimed "22 clubs per division", "176 NPC clubs", "9 countries" and
 * a "Brazil launch" while `StarterConfig::$enabledCountries` was `['EN']` — every
 * one of those was wrong, and there was no mechanism that would ever have caught
 * it. Reading from the database means the copy cannot drift again.
 *
 * Consumed twice: served as JSON at `/api/world/overview`, and called directly by
 * LandingController so the page server-renders rather than fetching its own origin.
 */
class WorldOverviewService
{
    public const TTL = 3600;

    private const CACHE_KEY = 'world_overview';

    public function __construct(
        private readonly LeagueRepository $leagueRepository,
        private readonly NpcClubRepository $npcClubRepository,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly CacheInterface $cache,
    ) {}

    /** @return array<string, mixed> */
    public function getOverview(): array
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
        $config = $this->starterConfigRepository->getConfig();

        // The player-facing list, NOT Country::generationCapable(). A country can
        // be buildable by the world generator long before it is offered to players,
        // and advertising the wrong one is exactly how "9 countries" got shipped.
        $enabledCodes = array_values(array_filter(
            $config->getEnabledCountries(),
            static fn ($code): bool => Country::tryFrom((string) $code) !== null
        ));

        $clubCounts = $this->npcClubRepository->getCountsByCountryAndTier();

        $countries = [];
        $tiersByCountry = [];
        $npcClubTotal = 0;

        foreach ($enabledCodes as $code) {
            $country = Country::from((string) $code);
            $countries[] = ['code' => $country->value, 'name' => $country->label()];

            $tiers = [];
            foreach ($this->leagueRepository->findByCountry($country->value) as $league) {
                $clubs = (int) ($clubCounts[$country->value][$league->getTier()] ?? 0);
                $npcClubTotal += $clubs;

                $tiers[] = [
                    'tier'              => $league->getTier(),
                    'name'              => $league->getName(),
                    'clubs'             => $clubs,
                    'promotionSpots'    => $league->getPromotionSpots(),
                    // Money columns are nullable bigint pence. A tier with no
                    // value configured returns null and the caller omits the row —
                    // printing "£0" would read as a deliberate figure.
                    'prizeMoney'        => self::pence($league->getPrizeMoney()),
                    'tvDeal'            => self::pence($league->getTvDeal()),
                    'leaguePositionPot' => self::pence($league->getLeaguePositionPot()),
                ];
            }

            $tiersByCountry[$country->value] = $tiers;
        }

        $primary = $countries[0]['code'] ?? null;
        $tiers   = $primary !== null ? $tiersByCountry[$primary] : [];

        return [
            'countries' => $countries,
            'tiers'     => $tiers,
            'tiersByCountry' => $tiersByCountry,
            'totals'    => [
                'tierCount'             => count($tiers),
                'npcClubs'              => $npcClubTotal,
                // Derived from the enum, so the hero can never again disagree
                // with the rest of the page about how many boards there are.
                'leaderboardCategories' => count(LeaderboardCategory::cases()),
                'playableCountries'     => count($countries),
                'clubsPerTier'          => self::clubsPerTier($tiers),
            ],
            'starter' => [
                'startingBalance'      => $config->getStartingBalance(),
                'startingBalancePence' => $config->getStartingBalance() * 100,
                'squadSize'            => $config->getStarterPlayerCount(),
                'staff' => [
                    'manager'             => $config->getStarterManagerCount(),
                    'coach'               => $config->getStarterCoachCount(),
                    'scout'               => $config->getStarterScoutCount(),
                    'directorOfFootball'  => $config->getStarterDirectorOfFootballCount(),
                    'facilityManager'     => $config->getStarterFacilityManagerCount(),
                    'chairman'            => $config->getStarterChairmanCount(),
                ],
            ],
            'generatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }

    /** Pence → whole pounds. Null stays null so callers can omit the row. */
    private static function pence(?int $value): ?int
    {
        return $value === null ? null : intdiv($value, 100);
    }

    /**
     * A human summary of division size: "10" when every tier matches, "10–12"
     * when they differ, null when there are no clubs yet. The old copy asserted
     * a single flat number for all eight tiers, which was never true.
     *
     * @param list<array{clubs: int, ...}> $tiers
     */
    private static function clubsPerTier(array $tiers): ?string
    {
        $counts = array_filter(array_column($tiers, 'clubs'), static fn (int $n): bool => $n > 0);
        if ($counts === []) {
            return null;
        }

        $min = min($counts);
        $max = max($counts);

        return $min === $max ? (string) $min : "{$min}–{$max}";
    }
}
