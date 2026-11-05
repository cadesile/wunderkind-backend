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

    /**
     * Range the ACCOUNT's opening credit is drawn from, in pence. Default £5,000,000-£6,000,000.
     *
     * A DIFFERENT THING FROM $startingBalance ABOVE, and conflating the two is the mistake this
     * comment exists to prevent. $startingBalance is the CLUB's transfer kitty — device-owned,
     * reassigned absolutely by every sync, and spent on players. This pair is the ACCOUNT's
     * UserLedger credit: backend-owned, durable, and what land and stadiums are actually
     * charged against. One player has many clubs and exactly one account balance.
     *
     * A RANGE RATHER THAN A VALUE because every account getting the identical opening figure
     * makes the first decision — which town can I afford — the same for everybody. The default
     * span matches what the prototype's land step was built against.
     *
     * BIGINT, not integer like $startingBalance. £6,000,000 is 600,000,000 pence, which fits a
     * 4-byte int, but the ceiling is admin-editable and 4 bytes cap at £21,474,836 — the same
     * trap that made Version20261103230000 necessary for user_ledger. Not worth re-learning.
     */
    #[ORM\Column(type: 'bigint')]
    private int $userStartingBalanceMinPence = 500_000_000;

    #[ORM\Column(type: 'bigint')]
    private int $userStartingBalanceMaxPence = 600_000_000;

    /**
     * How many AI land valuations one account may ever ask for. **Zero means unlimited.**
     *
     * Each `POST /api/locations/{id}/enrich` that actually dispatches costs one billed external
     * API call, so without a ceiling a single account can spend the whole enrichment budget by
     * walking the catalogue — ~4,200 towns at the time of writing.
     *
     * ZERO IS UNLIMITED, NOT ZERO, and that asymmetry is deliberate. The column arrives on
     * databases where the feature is already deployed and in use; a default that meant "nobody
     * may enrich anything" would silently break a working endpoint the moment the migration ran.
     * Unlimited is the behaviour those rows already have, so unlimited is what the default has
     * to preserve. An operator who genuinely wants to switch enrichment off has the provider
     * setting for that.
     *
     * Counted against dispatches, not requests — see User::$enrichmentCount.
     *
     * Default: 25.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 25])]
    private int $maxEnrichmentRequestsPerUser = 25;

    /**
     * What finishing the onboarding quiz is worth, as a multiple of the account's OWN grant.
     *
     * A MULTIPLIER AND NOT A FIXED AMOUNT, which docs/api/setup-flow.md #4 settled and this
     * comment records the reason for: the grant is a RANGE resolved per account
     * (resolveUserStartingBalancePence above), so a fixed bonus would be a larger proportion of
     * an unlucky account's grant than a lucky one's — backwards from the intent. A multiple of
     * the account's own figure is the same reward to everyone.
     *
     * SCALED BY THE SHARE ANSWERED CORRECTLY, so a 1.0 multiplier doubles the grant only on a
     * perfect score. See SetupQuizService::bonusPence().
     *
     * 0.0 TURNS THE BONUS OFF and is a legitimate setting — the quiz still runs and still
     * records answers, it just pays nothing. Default 1.0: a perfect score doubles the grant,
     * which is the question the prototype note actually asked.
     */
    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    private float $quizBonusMultiplier = 1.0;

    /**
     * Smallest opening float a player may stake into a new club, in pence. **Zero means no
     * minimum**, and that is the default.
     *
     * WHY OFF BY DEFAULT. The client's funding step lets the player stake "a partial sum or
     * 100%" of their balance, with no floor in the agreed flow. docs/api/setup-flow.md had
     * proposed enforcing a floor projected from running costs, and POST /api/club/fund does
     * compute and return that projection — but it refuses nothing unless an operator sets this,
     * because refusing a stake the player was told they could make is a worse failure than
     * letting them underfund a club they can top up from a dividend later.
     *
     * Same "zero is off, not zero" asymmetry as maxEnrichmentRequestsPerUser, and for the same
     * reason: the column arrives on a database whose endpoint is about to go live, and a
     * default that started refusing stakes would be a migration changing behaviour.
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $clubFundingMinimumPence = 0;

    /**
     * How many weeks the funding step should project running costs over. Default: 13 (a quarter).
     *
     * PUBLISHED FOR THE CLIENT TO PROJECT WITH — the server cannot do the projection itself, and
     * that is a fact about the data model rather than a gap to close later.
     * POST /api/market/assign deletes players and staff from the pool when they are hired ("no
     * club_id" — see that controller), so a club's squad and staff exist ONLY on the device. The
     * server therefore knows a club's facilities and nothing about who it employs.
     *
     * So the split is: the client sums candidate wages from GET /api/market/data (staff and, as
     * of 2026-11-05, scouts both carry `weeklySalary` there) multiplied by this many weeks; the
     * server returns the one running cost it does hold, facility upkeep, on POST /api/club/fund.
     * Neither half is enforced unless clubFundingMinimumPence above is set.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 13])]
    private int $clubFundingProjectionWeeks = 13;

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
                'bodyKey' => 'ngs.tut.owner.bodyRole',
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

    public function getMaxEnrichmentRequestsPerUser(): int { return $this->maxEnrichmentRequestsPerUser; }

    /** Clamped at zero rather than throwing: a negative ceiling is a typo, and zero already means unlimited. */
    public function setMaxEnrichmentRequestsPerUser(int $max): static
    {
        $this->maxEnrichmentRequestsPerUser = max(0, $max);

        return $this;
    }

    /** True when this config places no ceiling on enrichment requests at all. */
    public function enrichmentRequestsAreUnlimited(): bool
    {
        return $this->maxEnrichmentRequestsPerUser === 0;
    }

    // Cast on read: Doctrine hydrates a bigint column as a string.
    public function getUserStartingBalanceMinPence(): int { return (int) $this->userStartingBalanceMinPence; }
    public function getUserStartingBalanceMaxPence(): int { return (int) $this->userStartingBalanceMaxPence; }

    /**
     * Sets both ends at once, because they are only meaningful as a pair.
     *
     * NORMALISES RATHER THAN THROWING. An admin who types the bounds the wrong way round gets
     * the range they obviously meant, and a negative floor is clamped to zero — this is a form
     * handler's input, not a programming error, and refusing to save the whole starter-config
     * form over a transposed pair would be worse than fixing it.
     */
    public function setUserStartingBalanceRangePence(int $minPence, int $maxPence): static
    {
        $min = max(0, min($minPence, $maxPence));
        $max = max(0, max($minPence, $maxPence));

        $this->userStartingBalanceMinPence = $min;
        $this->userStartingBalanceMaxPence = $max;

        return $this;
    }

    /**
     * Single-ended setters, for reflective config import.
     *
     * ConfigImportExportService exports every property and imports it back through a matching
     * setter, so the paired setter above is not enough on its own — ConfigImportExportCoverageTest
     * fails on any exported field that cannot be imported, which is exactly how this omission was
     * caught.
     *
     * These clamp to zero but deliberately do NOT clamp against each other: an import writes the
     * two in whatever order reflection hands them over, so a min-versus-max check here would
     * reject a perfectly good pair purely because of field ordering. The ordering invariant is
     * enforced where it actually matters instead — resolveUserStartingBalancePence() normalises
     * on read, so a transposed pair still yields a sane figure.
     */
    public function setUserStartingBalanceMinPence(int $pence): static
    {
        $this->userStartingBalanceMinPence = max(0, $pence);

        return $this;
    }

    public function setUserStartingBalanceMaxPence(int $pence): static
    {
        $this->userStartingBalanceMaxPence = max(0, $pence);

        return $this;
    }

    public function getQuizBonusMultiplier(): float { return $this->quizBonusMultiplier; }
    public function setQuizBonusMultiplier(float $multiplier): static
    {
        // Negative would turn a bonus into a charge against the grant it is meant to top up.
        $this->quizBonusMultiplier = max(0.0, $multiplier);

        return $this;
    }

    public function getClubFundingMinimumPence(): int { return (int) $this->clubFundingMinimumPence; }
    public function setClubFundingMinimumPence(int $pence): static
    {
        $this->clubFundingMinimumPence = max(0, $pence);

        return $this;
    }

    public function getClubFundingProjectionWeeks(): int { return $this->clubFundingProjectionWeeks; }
    public function setClubFundingProjectionWeeks(int $weeks): static
    {
        // A zero-week projection is a projection of nothing, which would read as "no running
        // costs" rather than "no opinion". One week is the floor.
        $this->clubFundingProjectionWeeks = max(1, $weeks);

        return $this;
    }

    public function getUserStartingBalanceMinPounds(): int { return (int) round($this->getUserStartingBalanceMinPence() / 100); }
    public function getUserStartingBalanceMaxPounds(): int { return (int) round($this->getUserStartingBalanceMaxPence() / 100); }

    public function setUserStartingBalanceRangePounds(int $minPounds, int $maxPounds): static
    {
        return $this->setUserStartingBalanceRangePence($minPounds * 100, $maxPounds * 100);
    }

    /**
     * The opening credit for one specific account, in pence.
     *
     * DETERMINISTIC FROM THE SEED, not random, and that is load-bearing rather than fussy. The
     * grant is written once and is then real money in a ledger, so support has to be able to
     * answer "why did this account open at £5.4M?" months later — and the same account must
     * resolve to the same figure whether or not the grant row has been written yet. Pass the
     * user id as the seed; crc32 is plenty for spreading values across a range and is stable
     * across PHP versions and platforms, which rand() seeding is not.
     *
     * An inverted or zero-width range is already normalised by the setter, so this cannot
     * divide by a negative span.
     */
    public function resolveUserStartingBalancePence(string $seed): int
    {
        // NORMALISED ON READ, not trusted from storage. The paired setter orders the bounds, but
        // the single-ended setters above cannot (reflective import writes them in an arbitrary
        // order), so a transposed pair can legitimately be in the columns. Sorting here means
        // every caller gets a figure inside the range the operator meant, whichever way round it
        // was written.
        $a   = $this->getUserStartingBalanceMinPence();
        $b   = $this->getUserStartingBalanceMaxPence();
        $min = min($a, $b);
        $max = max($a, $b);

        if ($max <= $min) {
            return $min;
        }

        return $min + (int) (crc32($seed) % (($max - $min) + 1));
    }

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
