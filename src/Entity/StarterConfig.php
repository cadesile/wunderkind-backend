<?php

namespace App\Entity;

use App\Enum\ReputationTier;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-row configuration entity for new club initialisation.
 * The primary key is fixed at 1 — only one row ever exists.
 * Edit via EasyAdmin or the seeder migration; never create a second row.
 */
#[ORM\Entity(repositoryClass: StarterConfigRepository::class)]
class StarterConfig
{
    /** Fixed primary key — enforces single-row constraint. */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;

    /** Starting balance in pence. Default: £50,000 = 5,000,000p */
    #[ORM\Column(type: 'integer')]
    private int $startingBalance = 5_000_000;

    /** Number of players assigned to a starter club. Default: 5 */
    #[ORM\Column(type: 'integer')]
    private int $starterPlayerCount = 5;

    /**
     * World-pack agent distribution: target NPC players per agent. The world pack
     * bounds the distinct agents it uses to ceil(estimatedPlayers / this). Default: 12.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 12])]
    private int $worldPackPlayersPerAgent = 12;

    /** Number of coaches assigned to a starter club. Default: 1 */
    #[ORM\Column(type: 'integer')]
    private int $starterCoachCount = 1;

    /** Number of scouts assigned to a starter club. Default: 1 */
    #[ORM\Column(type: 'integer')]
    private int $starterScoutCount = 1;

    /** Number of managers assigned to a starter club. Default: 1 */
    #[ORM\Column(type: 'integer')]
    private int $starterManagerCount = 1;

    /** Number of directors of football assigned to a starter club. Default: 0 */
    #[ORM\Column(type: 'integer')]
    private int $starterDirectorOfFootballCount = 0;

    /** Number of facility managers assigned to a starter club. Default: 0 */
    #[ORM\Column(type: 'integer')]
    private int $starterFacilityManagerCount = 0;

    /** Number of chairmen assigned to a starter club. Default: 1 */
    #[ORM\Column(type: 'integer')]
    private int $starterChairmanCount = 1;

    /**
     * Sponsor company-size tier assigned at club creation.
     * Matches CompanySize enum value. Default: 'SMALL'
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $starterSponsorTier = 'SMALL';

    /**
     * Starting tier of the club. Matches Tier enum value. Default: 'local'
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $starterClubTier = 'local';

    #[ORM\Column(type: 'json')]
    private array $defaultFacilities = [];

    #[ORM\Column(type: 'string', length: 20, enumType: ReputationTier::class)]
    private ReputationTier $starterReputationTier = ReputationTier::LOCAL;

    /**
     * Country codes (ClubCountryCode values) available to players at club creation.
     * Default: ['EN'] — England only.
     */
    #[ORM\Column(type: 'json')]
    private array $enabledCountries = ['EN'];

    /**
     * Global ability ranges per country and league tier.
     * Format: { "EN": { "1": { "min": 75, "max": 100 } } }
     */
    /**
     * Standard 8-tier ability ranges applied to every country that doesn't have
     * custom overrides. All supported country codes share these defaults; admins
     * can override per-country in the DB via EasyAdmin.
     */
    private const DEFAULT_TIER_RANGES = [
        '1' => ['min' => 75, 'max' => 95],
        '2' => ['min' => 65, 'max' => 85],
        '3' => ['min' => 55, 'max' => 75],
        '4' => ['min' => 45, 'max' => 65],
        '5' => ['min' => 35, 'max' => 55],
        '6' => ['min' => 25, 'max' => 45],
        '7' => ['min' => 15, 'max' => 35],
        '8' => ['min' => 10, 'max' => 25],
    ];

    #[ORM\Column(type: 'json')]
    private array $leagueAbilityRanges = [
        'EN' => self::DEFAULT_TIER_RANGES,
        'ES' => self::DEFAULT_TIER_RANGES,
        'IT' => self::DEFAULT_TIER_RANGES,
        'DE' => self::DEFAULT_TIER_RANGES,
        'FR' => self::DEFAULT_TIER_RANGES,
        'PT' => self::DEFAULT_TIER_RANGES,
        'NL' => self::DEFAULT_TIER_RANGES,
        'BR' => self::DEFAULT_TIER_RANGES,
        'AR' => self::DEFAULT_TIER_RANGES,
        'NG' => self::DEFAULT_TIER_RANGES,
        'GH' => self::DEFAULT_TIER_RANGES,
        'CI' => self::DEFAULT_TIER_RANGES,
        'SN' => self::DEFAULT_TIER_RANGES,
        'JP' => self::DEFAULT_TIER_RANGES,
        'KR' => self::DEFAULT_TIER_RANGES,
        'SE' => self::DEFAULT_TIER_RANGES,
        'DK' => self::DEFAULT_TIER_RANGES,
        'US' => self::DEFAULT_TIER_RANGES,
        'CA' => self::DEFAULT_TIER_RANGES,
        'IE' => self::DEFAULT_TIER_RANGES,
        'CN' => self::DEFAULT_TIER_RANGES,
    ];

    /**
     * Per-tier NPC squad and staff config. Keyed by tier number (1–8).
     * Each entry: playerMin, playerMax, managerCount, coachCount, chairmanCount,
     * directorOfFootballCount, facilityManagerCount, scoutCount, foreignPercent
     */
    #[ORM\Column(type: 'json')]
    private array $npcSquadConfig = [
        '1' => ['playerMin' => 20, 'playerMax' => 24, 'managerCount' => 1, 'coachCount' => 5, 'chairmanCount' => 1, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 2, 'scoutCount' => 3, 'foreignPercent' => 60],
        '2' => ['playerMin' => 18, 'playerMax' => 22, 'managerCount' => 1, 'coachCount' => 4, 'chairmanCount' => 1, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 1, 'scoutCount' => 2, 'foreignPercent' => 45],
        '3' => ['playerMin' => 16, 'playerMax' => 20, 'managerCount' => 1, 'coachCount' => 3, 'chairmanCount' => 1, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 1, 'scoutCount' => 2, 'foreignPercent' => 30],
        '4' => ['playerMin' => 15, 'playerMax' => 18, 'managerCount' => 1, 'coachCount' => 2, 'chairmanCount' => 1, 'directorOfFootballCount' => 0, 'facilityManagerCount' => 1, 'scoutCount' => 1, 'foreignPercent' => 20],
        '5' => ['playerMin' => 14, 'playerMax' => 17, 'managerCount' => 1, 'coachCount' => 2, 'chairmanCount' => 1, 'directorOfFootballCount' => 0, 'facilityManagerCount' => 1, 'scoutCount' => 1, 'foreignPercent' => 15],
        '6' => ['playerMin' => 13, 'playerMax' => 16, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 0, 'facilityManagerCount' => 0, 'scoutCount' => 1, 'foreignPercent' => 10],
        '7' => ['playerMin' => 12, 'playerMax' => 15, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 0, 'facilityManagerCount' => 0, 'scoutCount' => 0, 'foreignPercent' => 5],
        '8' => ['playerMin' => 11, 'playerMax' => 14, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 0, 'facilityManagerCount' => 0, 'scoutCount' => 0, 'foreignPercent' => 3],
    ];

    /**
     * Initial fan base count ranges per league tier.
     * Format: { "1": { "min": 50000, "max": 150000 }, "2": { "min": 10000, "max": 50000 } }
     *
     * @var array<string, array{min: int, max: int}>
     */
    /**
     * Optional first-run guidance for the app's new-game SETUP flow: `{version, items[]}`.
     *
     * Served verbatim as `tutorial` by `StarterConfigController::index()`. The client
     * validates it item by item in `parseTutorial` and **cannot throw** — setup is the one
     * route a new account cannot skip — so anything malformed, of an unknown kind, anchored
     * to an unknown step, or naming a copy key the translation catalogue lacks is dropped
     * SILENTLY. Nothing reports it. That is why `app:check-setup-tutorial` exists and why
     * this column is validated rather than trusted; see `docs/api/setup-tutorial.md`.
     *
     * NOT named `tutorialConfig`: `Club::$tutorialCompletedAt` is a different and
     * differently-scoped concept (per-club "setup finished"), and two unrelated `tutorial`
     * things in one domain is how that one went stale unnoticed for five weeks. This is
     * global config; that is per-club state.
     *
     * Non-nullable with a seeded default, like every other JSON column on this entity —
     * which also avoids `ConfigImportExportService::coerce()`, whose null branch throws
     * unless the setter takes `?array`. An empty array is the "no tutorial" state.
     *
     * Copy keys are `ngs.tut.*`. They are NOT needed for English to render — the app's
     * bundled catalogue carries all 34 and API translations merge on top of it — but they
     * must exist as `TranslationKey` rows for the copy to be reworded without an app build,
     * which is the point of it living here. Get them by importing the app's
     * `locales/en/translations.json` at `/admin/translations/import`; that file is the single
     * source of those strings, so they are deliberately not re-typed on this side.
     */
    #[ORM\Column(type: 'json')]
    private array $setupTutorialConfig = [
        'version' => 1,
        'items' => [
            [
                'id' => 'brief-owner',
                'kind' => 'briefing',
                'anchor' => [
                    'before' => 'avatar',
                ],
                'modes' => ['first'],
                'titleKey' => 'ngs.tut.owner.title',
                'bodyKey' => 'ngs.tut.owner.body',
                'pointKeys' => ['ngs.tut.owner.p1', 'ngs.tut.owner.p2'],
                'ctaKey' => 'ngs.tut.cta.gotIt',
            ],
            [
                'id' => 'brief-club',
                'kind' => 'briefing',
                'anchor' => [
                    'before' => 'country',
                ],
                'modes' => ['first', 'newClub'],
                'titleKey' => 'ngs.tut.club.title',
                'bodyKey' => 'ngs.tut.club.body',
                'pointKeys' => ['ngs.tut.club.p1', 'ngs.tut.club.p2'],
                'ctaKey' => 'ngs.tut.cta.gotIt',
            ],
            [
                'id' => 'brief-identity',
                'kind' => 'briefing',
                'anchor' => [
                    'before' => 'badge',
                ],
                'modes' => ['first', 'newClub'],
                'titleKey' => 'ngs.tut.identity.title',
                'bodyKey' => 'ngs.tut.identity.body',
                'pointKeys' => ['ngs.tut.identity.p1', 'ngs.tut.identity.p2', 'ngs.tut.identity.p3'],
                'ctaKey' => 'ngs.tut.cta.gotIt',
            ],
            [
                'id' => 'brief-staff',
                'kind' => 'briefing',
                'anchor' => [
                    'before' => 'hire:chairman',
                ],
                'modes' => ['first', 'newClub'],
                'titleKey' => 'ngs.tut.staff.title',
                'bodyKey' => 'ngs.tut.staff.body',
                'pointKeys' => ['ngs.tut.staff.p1', 'ngs.tut.staff.p2', 'ngs.tut.staff.p3', 'ngs.tut.staff.p4'],
                'ctaKey' => 'ngs.tut.cta.gotIt',
            ],
            [
                'id' => 'brief-review',
                'kind' => 'briefing',
                'anchor' => [
                    'before' => 'overview',
                ],
                'modes' => ['first', 'newClub'],
                'titleKey' => 'ngs.tut.review.title',
                'bodyKey' => 'ngs.tut.review.body',
                'pointKeys' => ['ngs.tut.review.p1', 'ngs.tut.review.p2'],
                'ctaKey' => 'ngs.tut.cta.gotIt',
            ],
            [
                'id' => 'note-avatar',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'avatar',
                ],
                'modes' => ['first'],
                'bodyKey' => 'ngs.tut.note.avatar',
                'dismissible' => true,
            ],
            [
                'id' => 'note-owner',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'owner',
                ],
                'modes' => ['first'],
                'bodyKey' => 'ngs.tut.note.owner',
                'dismissible' => true,
            ],
            [
                'id' => 'note-country',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'country',
                ],
                'bodyKey' => 'ngs.tut.note.country',
                'dismissible' => true,
            ],
            [
                'id' => 'note-club',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'club',
                ],
                'bodyKey' => 'ngs.tut.note.club',
                'dismissible' => true,
            ],
            [
                'id' => 'note-badge',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'badge',
                ],
                'bodyKey' => 'ngs.tut.note.badge',
                'dismissible' => true,
            ],
            [
                'id' => 'note-kits',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'kits',
                ],
                'bodyKey' => 'ngs.tut.note.kits',
                'dismissible' => true,
            ],
            [
                'id' => 'note-stadium',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'stadium',
                ],
                'bodyKey' => 'ngs.tut.note.stadium',
                'dismissible' => true,
            ],
            [
                'id' => 'note-hire',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'hire',
                ],
                'bodyKey' => 'ngs.tut.note.hire',
                'dismissible' => true,
            ],
            [
                'id' => 'note-role',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'role',
                ],
                'bodyKey' => 'ngs.tut.note.role',
                'dismissible' => true,
            ],
            [
                'id' => 'note-overview',
                'kind' => 'note',
                'anchor' => [
                    'on' => 'overview',
                ],
                'bodyKey' => 'ngs.tut.note.overview',
                'dismissible' => true,
            ],
        ],
    ];

    #[ORM\Column(type: 'json')]
    private array $fanBaseRanges = [
        '1' => ['min' => 50000,  'max' => 150000],
        '2' => ['min' => 20000,  'max' => 70000],
        '3' => ['min' => 10000,  'max' => 30000],
        '4' => ['min' => 5000,   'max' => 15000],
        '5' => ['min' => 2000,   'max' => 8000],
        '6' => ['min' => 1000,   'max' => 4000],
        '7' => ['min' => 500,    'max' => 2000],
        '8' => ['min' => 200,    'max' => 1000],
    ];

    /**
     * Percentage increase applied to a club's fan base on promotion.
     * e.g. 0.20 = 20%. Default: 0.20
     */
    #[ORM\Column(type: 'float', options: ['default' => 0.20])]
    private float $fanBasePromotionIncrease = 0.20;

    /**
     * Percentage decrease applied to a club's fan base on relegation.
     * e.g. 0.10 = 10%. Default: 0.10
     */
    #[ORM\Column(type: 'float', options: ['default' => 0.10])]
    private float $fanBaseRelegationDecrease = 0.10;

    /** Returns a new instance pre-populated with all defaults. */
    public static function defaults(): self
    {
        return new self();
    }

    public function getId(): int { return $this->id; }

    public function getStartingBalance(): int { return $this->startingBalance; }
    public function setStartingBalance(int $v): static { $this->startingBalance = $v; return $this; }

    /** Virtual property for the admin form — accepts/returns pounds; storage remains pence. */
    public function getStartingBalancePounds(): int { return (int) round($this->startingBalance / 100); }
    public function setStartingBalancePounds(int $pounds): static { $this->startingBalance = $pounds * 100; return $this; }

    public function getStarterPlayerCount(): int { return $this->starterPlayerCount; }
    public function setStarterPlayerCount(int $v): static { $this->starterPlayerCount = $v; return $this; }

    public function getWorldPackPlayersPerAgent(): int { return $this->worldPackPlayersPerAgent; }
    public function setWorldPackPlayersPerAgent(int $v): static { $this->worldPackPlayersPerAgent = $v; return $this; }

    public function getStarterCoachCount(): int { return $this->starterCoachCount; }
    public function setStarterCoachCount(int $v): static { $this->starterCoachCount = $v; return $this; }

    public function getStarterScoutCount(): int { return $this->starterScoutCount; }
    public function setStarterScoutCount(int $v): static { $this->starterScoutCount = $v; return $this; }

    public function getStarterManagerCount(): int { return $this->starterManagerCount; }
    public function setStarterManagerCount(int $v): static { $this->starterManagerCount = $v; return $this; }

    public function getStarterDirectorOfFootballCount(): int { return $this->starterDirectorOfFootballCount; }
    public function setStarterDirectorOfFootballCount(int $v): static { $this->starterDirectorOfFootballCount = $v; return $this; }

    public function getStarterFacilityManagerCount(): int { return $this->starterFacilityManagerCount; }
    public function setStarterFacilityManagerCount(int $v): static { $this->starterFacilityManagerCount = $v; return $this; }

    public function getStarterChairmanCount(): int { return $this->starterChairmanCount; }
    public function setStarterChairmanCount(int $v): static { $this->starterChairmanCount = $v; return $this; }

    public function getStarterSponsorTier(): string { return $this->starterSponsorTier; }
    public function setStarterSponsorTier(string $v): static { $this->starterSponsorTier = $v; return $this; }

    public function getStarterClubTier(): string { return $this->starterClubTier; }
    public function setStarterClubTier(string $v): static { $this->starterClubTier = $v; return $this; }

    public function getDefaultFacilities(): array { return $this->defaultFacilities; }
    public function setDefaultFacilities(array $v): static { $this->defaultFacilities = $v; return $this; }

    public function getDefaultFacilitiesJson(): string
    {
        if (empty($this->defaultFacilities)) {
            return '{}';
        }
        return json_encode($this->defaultFacilities, JSON_PRETTY_PRINT) ?: '{}';
    }

    public function setDefaultFacilitiesJson(string $v): static
    {
        $this->defaultFacilities = json_decode($v, true) ?? [];
        return $this;
    }

    public function getStarterReputationTier(): ReputationTier { return $this->starterReputationTier; }
    public function setStarterReputationTier(ReputationTier $v): static { $this->starterReputationTier = $v; return $this; }

    public function getNpcSquadConfig(): array { return $this->npcSquadConfig; }
    public function setNpcSquadConfig(array $v): static { $this->npcSquadConfig = $v; return $this; }

    public function getNpcSquadConfigJson(): string
    {
        return json_encode($this->npcSquadConfig, JSON_PRETTY_PRINT) ?: '{}';
    }

    public function setNpcSquadConfigJson(string $v): static
    {
        $this->npcSquadConfig = json_decode($v, true) ?? [];
        return $this;
    }

    public function getEnabledCountries(): array { return $this->enabledCountries; }
    public function setEnabledCountries(array $v): static { $this->enabledCountries = $v; return $this; }

    public function getLeagueAbilityRanges(): array { return $this->leagueAbilityRanges; }
    public function setLeagueAbilityRanges(array $v): static { $this->leagueAbilityRanges = $v; return $this; }

    /** The tier-ability-range shape seeded for every country by default — see app:country:bootstrap. */
    public static function defaultTierRanges(): array { return self::DEFAULT_TIER_RANGES; }

    /** @return array<string, array{min: int, max: int}> */
    public function getFanBaseRanges(): array { return $this->fanBaseRanges; }
    /** @param array<string, array{min: int, max: int}> $v */
    public function setFanBaseRanges(array $v): static { $this->fanBaseRanges = $v; return $this; }

    public function getFanBaseRangesJson(): string
    {
        return json_encode($this->fanBaseRanges, JSON_PRETTY_PRINT) ?: '{}';
    }

    public function setFanBaseRangesJson(string $v): static
    {
        $this->fanBaseRanges = json_decode($v, true) ?? [];
        return $this;
    }

    public function getFanBasePromotionIncrease(): float { return $this->fanBasePromotionIncrease; }
    public function setFanBasePromotionIncrease(float $v): static { $this->fanBasePromotionIncrease = max(0.0, $v); return $this; }

    public function getFanBaseRelegationDecrease(): float { return $this->fanBaseRelegationDecrease; }
    public function setFanBaseRelegationDecrease(float $v): static { $this->fanBaseRelegationDecrease = max(0.0, $v); return $this; }

    public function getSetupTutorialConfig(): array { return $this->setupTutorialConfig; }
    /** @param array<string, mixed> $v */
    public function setSetupTutorialConfig(array $v): static { $this->setupTutorialConfig = $v; return $this; }

    public function getSetupTutorialConfigJson(): string
    {
        return json_encode($this->setupTutorialConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Admin textarea setter. Invalid JSON leaves the stored value ALONE rather than wiping
     * it to `[]` — a typo in a 15-item blob should not silently delete the tutorial. The
     * admin screen reports the parse error; `app:check-setup-tutorial` reports the rest.
     */
    public function setSetupTutorialConfigJson(string $v): static
    {
        $decoded = json_decode($v, true);
        if (is_array($decoded)) {
            $this->setupTutorialConfig = $decoded;
        }
        return $this;
    }
}
