<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FacilityTemplate;
use App\Entity\Location\Location;
use App\Entity\NpcClub;
use App\Enum\Appearance\BadgeCentre;
use App\Enum\Appearance\BadgePattern;
use App\Enum\Appearance\BadgeShape;
use App\Enum\Appearance\KitColor;
use App\Enum\Appearance\KitPart;
use App\Enum\Appearance\KitStyle;
use App\Enum\Country;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameConfigRepository;
use App\Repository\NpcClubRepository;
use App\Service\ClubInitializationService;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\Location\LocationCatalogueService;
use App\Service\Location\LocationClassificationService;
use App\Service\LeagueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class NpcClubGenerationService
{
    /**
     * Facility level ranges by tier band.
     * Each entry: [min, max, training range, stands range, other range]
     */
    private const FACILITY_LEVELS = [
        ['min' => 1, 'max' => 2, 'training' => [7, 9], 'stands' => [4, 5], 'other' => [3, 5]],
        ['min' => 3, 'max' => 4, 'training' => [5, 6], 'stands' => [3, 4], 'other' => [2, 3]],
        ['min' => 5, 'max' => 6, 'training' => [3, 4], 'stands' => [2, 3], 'other' => [1, 2]],
        ['min' => 7, 'max' => 8, 'training' => [1, 2], 'stands' => [0, 1], 'other' => [0, 1]],
    ];

    // Slugs classified by facility type for level band selection
    /**
     * How many of a country's biggest towns tier 1 is seeded with, before the capital is added
     * and before the weighted draw fills whatever is left.
     *
     * FIVE BECAUSE THAT IS WHAT THE LOCATIONS DASHBOARD CALLS "BIGGEST TOWNS", and an operator
     * comparing the two screens should see the same set. A const rather than configuration: it
     * is a statement about what a top division is, not a dial, and a country generating fewer
     * than five top-flight clubs simply takes the first `count` of them. See anchorPlaces().
     */
    private const ANCHOR_TOWNS = 5;

    /** Used when a country has no formats of its own, and when a stored one will not run. */
    private const FALLBACK_STADIUM_FORMATS = ['%s Stadium', '%s Ground', 'The %s Arena'];

    private const TRAINING_SLUGS = ['training_pitch', 'strength_suite'];
    private const STANDS_SLUGS   = ['north_stand', 'south_stand', 'east_stand', 'west_stand'];

    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly FacilityTemplateRepository  $facilityTemplateRepo,
        private readonly NpcClubRepository           $npcClubRepo,
        private readonly LeagueService               $leagueService,
        private readonly GameConfigRepository        $gameConfigRepository,
        private readonly LocationCatalogueService    $locationCatalogue,
        private readonly \App\Service\Economy\EconomyScaleResolver $economyScaleResolver,
        private readonly \App\Service\Location\NpcClubPlotSeatingService $plotSeating,
        // Suffixes and stadium formats now come from here, not from CountryContentRegistry
        // directly: an operator can author them on the locations dashboard, and the PHP
        // consts are the fallback. The registry is still read for allPlaceNames() and
        // suffixLeadsName(), neither of which is admin-editable.
        private readonly \App\Service\CountryContent\CountryNamingContentProvider $namingContent,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {}

    /**
     * How deep this country's pyramid is meant to be.
     *
     * DELEGATED SINCE 2026-11-12. The lookup itself moved to EconomyScaleResolver, which is now
     * the single owner of every per-country economy input — this was the only place in the
     * codebase that read leagueTierCount, and the resolver needs it anyway to clamp a requested
     * tier to a pyramid that does not have it. Kept as a method here because reputationForTier()
     * reads it on a hot loop and the resolver memoises per request.
     *
     * The reasoning behind the value is unchanged: it is the profile's DECLARED intent rather
     * than a count of league rows, because clubs are often generated while a structure is still
     * being built and rescaling them against a half-finished count bakes in wrong figures.
     */
    private function tierCountFor(string $country): int
    {
        return $this->economyScaleResolver->tierCountFor($country);
    }

    /** @return string[] */
    public function getPlaceNames(string $countryCode): array
    {
        return array_map(static fn(array $p) => $p['name'], $this->getPlaceData($countryCode));
    }

    /**
     * The curated generation pool for a country.
     *
     * Reads the `location` table rather than CountryContentRegistry::places(), which is where
     * these 865 records used to live as a PHP const. The returned shape is unchanged, which is
     * why every existing test of this class still passes untouched — see
     * LocationCatalogueService.
     *
     * @return array<array{name:string,population_size:int,region:string,is_capital?:bool,city_size?:string}>
     */
    public function getPlaceData(string $countryCode): array
    {
        return $this->locationCatalogue->placesForGeneration($countryCode);
    }

    /** @return string[] every name from the original full list that isn't in the curated generation pool */
    public function getRemainingPlaceNames(string $countryCode): array
    {
        $curated = $this->getPlaceNames($countryCode);
        $country = Country::tryFrom($countryCode);
        // Still the PHP const, deliberately. ALL_PLACE_NAMES_BY_COUNTRY is ~4,200 bare town
        // names with no coordinates, population or region — no geography at all — so migrating
        // it would create thousands of location rows that enrichment could never resolve and
        // that would pollute GET /api/locations. These names exist only to widen the club-name
        // picker.
        $all = $country !== null ? CountryContentRegistry::allPlaceNames($country) : [];

        return array_values(array_unique(array_diff($all, $curated)));
    }

    /**
     * Ranks places by population_size (descending) and tags each with a CitySize:
     * top 20% => BIG, bottom 50% => SMALL, remaining middle 30% => MEDIUM.
     * is_capital always forces BIG regardless of rank.
     *
     * The algorithm itself now lives in LocationClassificationService::classify(), which is
     * what persists `location.city_size`. This method stays as the public entry point its
     * existing callers and tests use, and delegates, so there is exactly one implementation of
     * the banding rather than a live copy here and a stored copy there that could disagree.
     *
     * @param array<array{name:string,population_size:int,region:string,is_capital?:bool}> $places
     * @return array<array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}>
     */
    public function classifyPlaces(array $places): array
    {
        return LocationClassificationService::classify($places);
    }

    /** @return string[] */
    public function getSuffixes(string $countryCode): array
    {
        $country = Country::tryFrom($countryCode);
        if ($country === null) {
            return [];
        }

        return array_merge(
            $this->namingContent->prestigeSuffixes($country),
            $this->namingContent->genericSuffixes($country),
        );
    }

    /** @param string[] $prestige @param string[] $generic */
    public function pickSuffixForCitySize(\App\Enum\CitySize $citySize, array $prestige, array $generic): string
    {
        $pool = match ($citySize) {
            \App\Enum\CitySize::BIG    => $prestige,
            \App\Enum\CitySize::SMALL  => $generic,
            \App\Enum\CitySize::MEDIUM => random_int(0, 1) === 0 ? $prestige : $generic,
        };

        return $pool[array_rand($pool)];
    }

    /** @return NpcClub[] */
    public function generateClubs(int $count, int $tier, string $country, bool $deleteExisting = false): array
    {
        $tierCount  = $this->tierCountFor($country);
        $tier       = max(1, min($tierCount, $tier));
        $slugs      = $this->getActiveFacilitySlugs();
        $bandIndex  = $this->getBandIndexForTier($tier);
        $levelBand  = self::FACILITY_LEVELS[$bandIndex];
        $countryEnum = Country::tryFrom($country);
        $placeData  = $this->locationCatalogue->placesForGenerationOrPlaceholder($country);
        $classifiedPlaces = $this->withBandStandings($this->classifyPlaces($placeData));
        $prestigeSuffixes = ($countryEnum !== null ? $this->namingContent->prestigeSuffixes($countryEnum) : []) ?: ['FC'];
        $genericSuffixes  = ($countryEnum !== null ? $this->namingContent->genericSuffixes($countryEnum) : []) ?: ['FC'];
        $weights          = $this->gameConfigRepository->getConfig()->getNpcClubSizeWeightsForTier($tier, $tierCount);
        $suffixLeads      = $countryEnum !== null && CountryContentRegistry::suffixLeadsName($countryEnum);
        $usedNames        = [];
        $clubs            = [];

        if ($deleteExisting) {
            $this->npcClubRepo->deleteByCountryAndTier($country, $tier);
        }

        // THE TOP DIVISION IS NOT A RANDOM DRAW. Every other tier is, but a country's biggest
        // clubs belong in its biggest cities, and leaving that to the weighted pick produced
        // England top flights with no London, Manchester or Birmingham in them far more often
        // than not. AFTER the delete above, so a regeneration re-anchors the same towns rather
        // than treating them as taken by the clubs it has just removed.
        $anchors = $tier === 1 ? $this->anchorPlaces($classifiedPlaces, $count) : [];

        for ($i = 0; $i < $count; $i++) {
            [$name, $place] = $this->generateName($classifiedPlaces, $usedNames, $prestigeSuffixes, $genericSuffixes, $weights, $suffixLeads, $anchors[$i] ?? null);
            $usedNames[]    = $name;
            $citySize       = $place['city_size'];
            $standing       = (float) ($place['standing'] ?? 0.5);
            $reputation     = $this->reputationForTier($tier, $citySize, $tierCount, $standing);
            $balance        = $this->balanceForTier($tier, $citySize, $standing);
            $facilities     = $this->buildFacilities($slugs, $levelBand, $bandIndex);
            $identity       = $this->generateIdentity($name);
            $stadiumName    = $this->generateStadiumName($place['name'], $country);

            $club = new NpcClub(
                name:           $name,
                country:        $country,
                tier:           $tier,
                reputation:     $reputation,
                primaryColor:   $identity['home']['primary'],
                secondaryColor: $identity['home']['secondary'],
                balance:        $balance,
                facilities:     $facilities,
                region:         $place['region'] ?? null,
                citySize:       $citySize,
                populationSize: (int) ($place['population_size'] ?? 0),
                isCapital:      (bool) ($place['is_capital'] ?? false),
                // The town this club plays in. getReference() rather than a lookup: the id came
                // straight out of findForGeneration() a moment ago, so the row is known to exist
                // and Doctrine needs only a proxy to write the FK — a find() here would be one
                // query per club.
                //
                // NULL on the placeholder path. A country with no locations yet generates from
                // LocationCatalogueService::PLACEHOLDER_PLACES, whose entries carry no id and
                // never will be location rows ("Capital FC", "Northern Town").
                location:       isset($place['id'])
                    ? $this->em->getReference(Location::class, Uuid::fromString((string) $place['id']))
                    : null,
            );
            $club->setStadiumName($stadiumName);
            $club->setPlayingStyle($this->playingStyleForTier($tier));
            $club->setFinancialApproach($this->financialApproachForTier($tier));
            $club->setManagerTemperament(random_int(30, 80));
            $club->setIdentity($identity);

            $this->em->persist($club);
            $this->leagueService->assignClubToLeague($club);
            $clubs[] = $club;
        }

        $this->em->flush();

        // SEATED AFTER THE FLUSH, because seatCountry() works in raw SQL over the rows that
        // exist — clubs still in the unit of work are invisible to it. Idempotent, so running
        // it on every generation costs one statement and re-seats nothing.
        //
        // A town with no plots yet seats nobody and that is correct: there is no ground to
        // stand on until enrichment prices it. Those clubs are picked up by
        // app:npc-clubs:seat-plots once there is.
        $this->plotSeating->seatCountry($country);

        return $clubs;
    }

    /**
     * Annotate each place with where it stands among the towns sharing its band, 0.0 to 1.0.
     *
     * THE BAND ON ITS OWN IS TOO COARSE TO SET A CLUB'S STRENGTH. reputationForTier() and
     * balanceForTier() narrow the tier's range by city size and then roll uniformly inside it,
     * so every BIG town drew from one identical range. England bands its top 20% as BIG, which
     * is 57 towns — London and Poole therefore produced top-flight clubs of indistinguishable
     * reputation and budget, which is the same flattening that put Poole in the top division in
     * the first place, showing up a second time in how strong the club turned out to be.
     *
     * LAND VALUE FIRST, POPULATION AS THE FALLBACK. What one plot costs here is the measure the
     * game already uses for how desirable a town is, and it is the one a player feels: London is
     * £150m a plot against Sheffield's £9m. A town is seeded long before it is priced, though,
     * so an unpriced town is ranked on population instead.
     *
     * TWO SEPARATE RANKINGS, NOT ONE MIXED LIST. Pence and people are different units and
     * ranking them together is meaningless. Priced towns are ranked against priced towns,
     * unpriced against unpriced, and because both come out as a percentile WITHIN THE SAME
     * BAND, the two results land on one comparable 0..1 scale.
     *
     * A BAND OF ONE GETS 0.5, not 1.0. Its single member is trivially both the strongest and
     * the weakest, and handing it the top of the range would make a tiny country's every club
     * maximal.
     *
     * @param list<array{population_size:int,land_pence?:?int,city_size:\App\Enum\CitySize,...}> $classifiedPlaces
     *
     * @return list<array{population_size:int,city_size:\App\Enum\CitySize,standing:float,...}>
     */
    private function withBandStandings(array $classifiedPlaces): array
    {
        /** @var array<string, array{priced: list<int>, unpriced: list<int>}> $members */
        $members = [];

        foreach ($classifiedPlaces as $i => $place) {
            $band = $place['city_size']->value;
            $members[$band] ??= ['priced' => [], 'unpriced' => []];
            $members[$band][isset($place['land_pence']) ? 'priced' : 'unpriced'][] = $i;
        }

        foreach ($members as $groups) {
            foreach ($groups as $group => $indexes) {
                $value = $group === 'priced'
                    ? static fn (array $p): int => (int) ($p['land_pence'] ?? 0)
                    : static fn (array $p): int => (int) ($p['population_size'] ?? 0);

                usort(
                    $indexes,
                    static fn (int $a, int $b): int => $value($classifiedPlaces[$a]) <=> $value($classifiedPlaces[$b]),
                );

                $last = count($indexes) - 1;

                foreach ($indexes as $rank => $i) {
                    $classifiedPlaces[$i]['standing'] = $last === 0 ? 0.5 : $rank / $last;
                }
            }
        }

        return $classifiedPlaces;
    }

    /**
     * The towns a top division is guaranteed to contain, in the order they are handed out.
     *
     * THE CAPITAL FIRST, THEN THE BIGGEST. Those are two different towns in plenty of
     * countries — Abuja is Nigeria's capital, Lagos is its largest — and both deserve a
     * top-flight club, so the capital is prepended rather than relied on to appear by
     * population. Where the capital is also the biggest, as in England, it appears once.
     *
     * `is_capital` HAS A JOB AGAIN. Until now the flag did exactly one thing: force a town BIG
     * in LocationClassificationService::classify(), ahead of the rank bands. It was then
     * recorded on the club and read by the export, the API and the CRUD screen, but nothing
     * ever used it to decide that a club should EXIST there — and for a country whose capital
     * is already its largest town, the forced-BIG override changed nothing at all.
     *
     * SKIPS TOWNS THAT ALREADY HAVE A TIER-1 CLUB. Generating a second batch without
     * --delete-existing would otherwise stack a new club on the same five towns every run.
     * Two clubs in one city is allowed — the name dedupe has always permitted it and big
     * cities genuinely have several — but it should fall out of the draw, not be manufactured.
     *
     * NO ids ON THE PLACEHOLDER PATH. A country with no locations generates from
     * LocationCatalogueService::PLACEHOLDER_PLACES, whose rows have no id and so can never be
     * found "taken"; it anchors on "Capital" and returns, which is the right answer for a set
     * that exists only to keep generation working before any town is seeded.
     *
     * @param array<array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}> $classifiedPlaces
     *        In population order — classify() re-indexes by population descending, which is
     *        what makes "the biggest" the front of the list rather than another sort here.
     * @param int $count how many clubs this run creates; never return more anchors than that
     *
     * @return list<array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}>
     */
    private function anchorPlaces(array $classifiedPlaces, int $count): array
    {
        if ($classifiedPlaces === [] || $count < 1) {
            return [];
        }

        $candidates = array_slice($classifiedPlaces, 0, self::ANCHOR_TOWNS);

        foreach ($classifiedPlaces as $i => $place) {
            if (!empty($place['is_capital'])) {
                if ($i >= self::ANCHOR_TOWNS) {
                    array_unshift($candidates, $place);
                }

                break;
            }
        }

        $ids = [];
        foreach ($candidates as $place) {
            if (isset($place['id'])) {
                $ids[] = (string) $place['id'];
            }
        }

        $taken = $ids === [] ? [] : $this->npcClubRepo->findLocationIdsWithClubInTier($ids, 1);

        $anchors = [];
        foreach ($candidates as $place) {
            if (isset($place['id']) && isset($taken[(string) $place['id']])) {
                continue;
            }

            $anchors[] = $place;
        }

        return array_slice($anchors, 0, $count);
    }

    /**
     * Weighted random pick across classified places.
     *
     * TWO FACTORS, NOT ONE. The tier's weight for a band still decides how much of the draw
     * that band gets. Within the band, a place's share is now proportional to the SQUARE ROOT
     * of its population instead of being flat.
     *
     * FLAT WAS THE BUG. The weight used to be the band's percentage divided by the number of
     * places in it, so every BIG town had identical odds. England bands its top 20% as BIG,
     * which is 57 towns — so "big" carried no information about WHICH big, and a 5-club top
     * division contained London about 6% of the time. Bolton, Oxford, Plymouth, Poole and
     * Southampton as a country's entire top flight is that 94%, not a fluke.
     *
     * WHY THE ROOT RATHER THAN POPULATION ITSELF. London is 8.98m against Poole's 151k, 59:1.
     * Raw proportionality hands London 27% of every tier-1 draw and still 16% of a mid-table
     * one — a pyramid of London clubs all the way down. The root compresses that to roughly
     * 6:1, which reads as "the big cities dominate" without erasing everywhere else. Measured
     * against England's 284 towns: London 7.1% of a tier-1 draw, Birmingham 2.5%, Manchester
     * 1.8%, Poole 0.2%. NpcClubPlaceWeightingTest pins the ordering rather than the figures.
     *
     * A place with no population cannot reach here on the real path — findForGeneration()
     * excludes them — but max(1, …) keeps the placeholder set, and any future caller, from
     * making a town unreachable or dividing by zero.
     *
     * @param array<array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}> $classifiedPlaces
     * @param array{big:float,medium:float,small:float} $weights
     * @return array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}
     */
    public function pickWeightedPlace(array $classifiedPlaces, array $weights): array
    {
        $pull       = [];
        $bucketPull = ['big' => 0.0, 'medium' => 0.0, 'small' => 0.0];

        foreach ($classifiedPlaces as $i => $place) {
            $bucket               = strtolower($place['city_size']->value);
            $pull[$i]             = sqrt((float) max(1, (int) ($place['population_size'] ?? 0)));
            $bucketPull[$bucket]  = ($bucketPull[$bucket] ?? 0.0) + $pull[$i];
        }

        $cumulative = [];
        $total = 0.0;
        foreach ($classifiedPlaces as $i => $place) {
            $bucket = strtolower($place['city_size']->value);
            $share  = $pull[$i] / max(0.0001, $bucketPull[$bucket] ?? 0.0);
            // Floored on the PRODUCT, not on the band weight. A band the tier gives 0% to still
            // has to be reachable — pickWeightedPlace is the only source of places, and a
            // country whose every town lands in one band would otherwise have a total of zero.
            $weight = max(0.0001, (float) ($weights[$bucket] ?? 0) * $share);
            $total += $weight;
            $cumulative[$i] = $total;
        }

        $roll = (mt_rand() / mt_getrandmax()) * $total;
        foreach ($cumulative as $i => $upperBound) {
            if ($roll <= $upperBound) {
                return $classifiedPlaces[$i];
            }
        }

        return $classifiedPlaces[array_key_last($classifiedPlaces)];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return string[] active facility slugs from DB */
    private function getActiveFacilitySlugs(): array
    {
        $templates = $this->facilityTemplateRepo->findBy(['isActive' => true]);
        return array_map(fn(FacilityTemplate $t) => $t->getSlug(), $templates);
    }

    private function getBandIndexForTier(int $tier): int
    {
        foreach (self::FACILITY_LEVELS as $i => $band) {
            if ($tier >= $band['min'] && $tier <= $band['max']) {
                return $i;
            }
        }
        return 3;
    }

    private function buildFacilities(array $slugs, array $band, int $bandIndex): array
    {
        $config     = $this->gameConfigRepository->getConfig();
        $facilities = [];
        foreach ($slugs as $slug) {
            $override = $config->getNpcFacilityLevelRangeForSlugAndBand($slug, $bandIndex);
            if ($override !== null) {
                $min = (int) $override['min'];
                $max = (int) $override['max'];
                if ($min === 0 && $max === 0) {
                    continue; // facility excluded for this tier band
                }
                $max = max($min, $max);
            } elseif (in_array($slug, self::TRAINING_SLUGS, true)) {
                [$min, $max] = $band['training'];
            } elseif (in_array($slug, self::STANDS_SLUGS, true)) {
                [$min, $max] = $band['stands'];
            } else {
                [$min, $max] = $band['other'];
            }
            $facilities[$slug] = random_int($min, $max);
        }
        return $facilities;
    }

    /**
     * @param array<array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}> $classifiedPlaces
     * @param string[] $prestigeSuffixes
     * @param string[] $genericSuffixes
     * @param array{big:float,medium:float,small:float} $weights
     * @param ?array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize} $forcedPlace
     *        The town this club MUST play in, bypassing the weighted draw. Used by the tier-1
     *        anchors; the retry loop then varies the suffix alone, which is the only part of
     *        the name left to vary.
     * @return array{0:string,1:array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}}
     */
    private function generateName(array $classifiedPlaces, array $usedNames, array $prestigeSuffixes, array $genericSuffixes, array $weights, bool $suffixLeads = false, ?array $forcedPlace = null): array
    {
        $attempts = 0;
        do {
            $place  = $forcedPlace ?? $this->pickWeightedPlace($classifiedPlaces, $weights);
            $suffix = $this->pickSuffixForCitySize($place['city_size'], $prestigeSuffixes, $genericSuffixes);
            // Most countries read "{place} {suffix}" (Madrid CF, Lisboa SC). A few
            // put the club's own name first and the place second — Polish is
            // uniformly like this (Legia Warszawa, Górnik Zabrze), and composing
            // it the other way round produces names no Polish speaker would
            // recognise. See CountryContentRegistry::suffixLeadsName().
            $name   = $suffixLeads ? "{$suffix} {$place['name']}" : "{$place['name']} {$suffix}";
            $attempts++;
        } while (in_array($name, $usedNames, true) && $attempts < 50);

        return [$name, $place];
    }

    /**
     * Public one-off club name generator — same place+suffix composition as
     * generateClubs(), but for a single name outside the NpcClub generation pipeline
     * (e.g. CompetitionSpoofEntrantService renaming a cloned club). Tier only feeds the
     * place-size weighting flavor here, not a persisted value, so it defaults to a
     * mid-table tier.
     *
     * @param string[] $usedNames Names to avoid colliding with.
     */
    public function generateClubName(string $countryCode, array $usedNames = [], int $tier = 4): string
    {
        $countryEnum = Country::tryFrom($countryCode);
        $placeData = $this->locationCatalogue->placesForGenerationOrPlaceholder($countryCode);
        $classifiedPlaces = $this->classifyPlaces($placeData);
        $prestigeSuffixes = ($countryEnum !== null ? $this->namingContent->prestigeSuffixes($countryEnum) : []) ?: ['FC'];
        $genericSuffixes  = ($countryEnum !== null ? $this->namingContent->genericSuffixes($countryEnum) : []) ?: ['FC'];
        $tierCount        = $this->tierCountFor($countryCode);
        $weights          = $this->gameConfigRepository->getConfig()->getNpcClubSizeWeightsForTier(max(1, min($tierCount, $tier)), $tierCount);

        $suffixLeads = $countryEnum !== null && CountryContentRegistry::suffixLeadsName($countryEnum);

        [$name] = $this->generateName($classifiedPlaces, $usedNames, $prestigeSuffixes, $genericSuffixes, $weights, $suffixLeads);

        return $name;
    }

    public function generateStadiumName(string $place, string $country): string
    {
        $countryEnum = Country::tryFrom($country);
        $formats = ($countryEnum !== null ? $this->namingContent->stadiumFormats($countryEnum) : []) ?: self::FALLBACK_STADIUM_FORMATS;
        $format  = $formats[array_rand($formats)];

        // WRAPPED, because a format is now operator-typed and sprintf() THROWS in PHP 8 on a
        // malformed one. NamingContentValidator refuses a bad template at the form, but a value
        // written by a direct SQL edit, an older build or a future CRUD field would still
        // arrive here — and a fatal inside club generation is a long way from whatever stored
        // it. Degrading to the generic name is the right failure: the club still exists.
        try {
            return sprintf($format, $place);
        } catch (\Throwable $e) {
            $this->logger->warning('Unusable stadium format for {country}; fell back to the generic name.', [
                'country' => $country,
                'format'  => $format,
                'error'   => $e->getMessage(),
            ]);

            return sprintf(self::FALLBACK_STADIUM_FORMATS[0], $place);
        }
    }

    /** @return array{0:int,1:int} */
    /**
     * Roll inside a range, in the half of it this town's standing has earned.
     *
     * A SLIDING HALF-WIDTH WINDOW rather than a point. Reading the value straight off the
     * standing would make every club deterministic — the same town would always produce the
     * same reputation — and rolling uniformly across the whole range is what made London and
     * Poole indistinguishable. A window of half the range, slid from the bottom at standing 0
     * to the top at standing 1, keeps both: clubs still vary, and a club in a more valuable
     * town is reliably the stronger of the two.
     *
     * The windows of the strongest and weakest town in a band therefore meet at a point and
     * no more (one point of overlap when the span is odd), while every town between them
     * overlaps its neighbours heavily. That is the intended shape: ordering holds firmly
     * across the extremes and blurs between neighbours, where it should.
     *
     * SO A SINGLE PAIR OF CLUBS IS NOT ORDERED unless they sit at opposite ends of their band.
     * Two towns a few ranks apart draw from windows that overlap most of the way, and which
     * comes out higher on any one roll is chance. The guarantee is about the trend, which is
     * what NpcClubPlaceWeightingTest measures over many runs rather than one.
     *
     * @param float $standing 0.0 (weakest in its band) to 1.0 (strongest)
     */
    private function rollAtStanding(int $min, int $max, float $standing): int
    {
        $standing = max(0.0, min(1.0, $standing));
        $span     = max(0, $max - $min);
        $window   = (int) round($span / 2);
        $low      = $min + (int) round($standing * ($span - $window));

        return random_int($low, min($max, $low + $window));
    }

    public function skewRange(int $min, int $max, \App\Enum\CitySize $citySize): array
    {
        $span = $max - $min;

        return match ($citySize) {
            \App\Enum\CitySize::BIG    => [min($max, $min + (int) round($span * 0.33)), $max],
            \App\Enum\CitySize::SMALL  => [$min, max($min, $min + (int) round($span * 0.66))],
            \App\Enum\CitySize::MEDIUM => [$min, $max],
        };
    }

    private function reputationForTier(int $tier, \App\Enum\CitySize $citySize, int $tierCount = 8, float $standing = 0.5): int
    {
        // Top tier → 70–90, bottom tier → 5–20, interpolated across the country's OWN
        // depth rather than a fixed eight. A 3-tier country's bottom division should be as
        // unglamorous as tier 8 is in a full pyramid; anchored at 7 it would come out
        // around 42–70, which reads as a respectable second division.
        $tierCount = max(1, min(8, $tierCount));
        $tier      = max(1, min($tierCount, $tier));
        $steps     = max(1, $tierCount - 1);
        $progress  = $tierCount === 1 ? 0.0 : ($tier - 1) / $steps;

        $minRep = max(1, (int) round(70 - $progress * 65));
        $maxRep = max(1, (int) round(90 - $progress * 70));
        [$min, $max] = $this->skewRange($minRep, $maxRep, $citySize);

        return $this->rollAtStanding($min, $max, $standing);
    }

    /**
     * NO $tierCount PARAMETER ANY MORE. The balance table is now indexed raw — tier t reads row
     * t — so a shallow country's bottom division draws its OWN tier's band rather than the
     * tier-8 one it used to be stretched onto. Reversing that was deliberate; the reasoning is
     * in GameConfig::getNpcClubBalanceRangeForTier(). Depth still shapes reputation, which
     * interpolates rather than looking a row up.
     */
    private function balanceForTier(int $tier, \App\Enum\CitySize $citySize, float $standing = 0.5): int
    {
        $range = $this->gameConfigRepository->getConfig()->getNpcClubBalanceRangeForTier($tier);
        $min   = max(0, (int) $range['min']);
        $max   = max($min, (int) $range['max']);
        [$skewMin, $skewMax] = $this->skewRange($min, $max, $citySize);

        return $this->rollAtStanding($skewMin, $skewMax, $standing);
    }

    /**
     * Kit + badge identity: a home kit, an away kit (each independently
     * random, same rules), and a shared badge. The home kit's primary/
     * secondary become the club's canonical primaryColor/secondaryColor (see
     * NpcClub::setIdentity()) — there's no separate color-pair generation any
     * more; this sprite's 12-color KitColor palette is the single source of
     * truth. Mirrors the frontend Kit Builder's randomKitConfig() rules
     * one-to-one (run independently for each of home/away).
     *
     * @return array<string, mixed>
     */
    public function generateIdentity(string $name): array
    {
        $home = $this->randomKitVariant();
        $away = $this->randomKitVariant();

        $kitColors = array_map(static fn (KitColor $c) => $c->value, KitColor::cases());
        $pick      = static fn (array $arr) => $arr[array_rand($arr)];

        $badgeFill = random_int(0, 1) === 1 ? $home['primary'] : $pick($kitColors);
        do {
            $badgeTrim = $pick($kitColors);
        } while ($badgeTrim === $badgeFill);
        do {
            $badgeSymbol = $pick($kitColors);
        } while ($badgeSymbol === $badgeFill);

        // badgeCentre excludes NONE (a blank badge isn't a useful default to
        // roll) and INITIALS (a generated club's initials are a mechanical
        // abbreviation, not a designed badge centre) — both stay valid manual
        // admin choices.
        $centreOptions = array_values(array_filter(
            BadgeCentre::cases(),
            static fn (BadgeCentre $c) => !in_array($c, [BadgeCentre::NONE, BadgeCentre::INITIALS], true),
        ));

        $initials = strtoupper(substr(
            preg_replace('/[^A-Za-z0-9]/', '', ClubInitializationService::generateAbbreviation($name)) ?? '',
            0,
            3,
        ));

        return [
            'home'         => $home,
            'away'         => $away,
            'badgeShape'   => $pick(array_map(static fn (BadgeShape $s) => $s->value, BadgeShape::cases())),
            'badgePattern' => $pick(array_map(static fn (BadgePattern $p) => $p->value, BadgePattern::cases())),
            'badgeCentre'  => $pick(array_map(static fn (BadgeCentre $c) => $c->value, $centreOptions)),
            'initials'     => $initials !== '' ? $initials : 'FC',
            'badgeFill'    => $badgeFill,
            'badgeTrim'    => $badgeTrim,
            'badgeSymbol'  => $badgeSymbol,
        ];
    }

    /** One kit variant (home or away): kit style + contrasting colors + shorts/socks. */
    private function randomKitVariant(): array
    {
        $kitColors = array_map(static fn (KitColor $c) => $c->value, KitColor::cases());
        $kitParts  = array_map(static fn (KitPart $p) => $p->value, KitPart::cases());
        $pick      = static fn (array $arr) => $arr[array_rand($arr)];

        $primary   = $pick($kitColors);
        $secondary = $this->pickContrastingKitColor($primary, $kitColors);

        return [
            'kit'       => $pick(array_map(static fn (KitStyle $s) => $s->value, KitStyle::cases())),
            'primary'   => $primary,
            'secondary' => $secondary,
            'shorts'    => $pick($kitParts),
            'socks'     => $pick($kitParts),
        ];
    }

    /**
     * Picks a color from $palette that both differs from and has a real WCAG
     * contrast ratio (>= 3.0) against $primary, so a generated kit's two
     * colors are actually visually distinguishable rather than merely
     * different. Reintroduces the contrast rule this service used to apply to
     * the club's flat primary/secondary color pair (removed when `identity`
     * replaced it — see `pickColorPair()`/`contrastRatio()`/`relativeLuminance()`
     * on `master`), adapted to the smaller 12-color KitColor palette and
     * applied per kit variant (home and away independently).
     */
    private function pickContrastingKitColor(string $primary, array $palette): string
    {
        // Try up to 20 random picks for a contrasting color.
        for ($i = 0; $i < 20; $i++) {
            $candidate = $palette[array_rand($palette)];
            if ($candidate !== $primary && $this->contrastRatio($primary, $candidate) >= 3.0) {
                return $candidate;
            }
        }

        // Fallback: whichever palette color yields the highest contrast.
        $best      = null;
        $bestRatio = 0.0;
        foreach ($palette as $candidate) {
            if ($candidate === $primary) {
                continue;
            }
            $ratio = $this->contrastRatio($primary, $candidate);
            if ($ratio > $bestRatio) {
                $bestRatio = $ratio;
                $best      = $candidate;
            }
        }

        return $best ?? $palette[0];
    }

    private function contrastRatio(string $hexA, string $hexB): float
    {
        $la = $this->relativeLuminance($hexA);
        $lb = $this->relativeLuminance($hexB);
        [$lighter, $darker] = $la > $lb ? [$la, $lb] : [$lb, $la];
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $r   = hexdec(substr($hex, 0, 2)) / 255;
        $g   = hexdec(substr($hex, 2, 2)) / 255;
        $b   = hexdec(substr($hex, 4, 2)) / 255;

        $linearise = static fn (float $c): float =>
            $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $linearise($r) + 0.7152 * $linearise($g) + 0.0722 * $linearise($b);
    }

    /**
     * A standalone contrasting color pair from the KitColor palette, for
     * callers that need just two club colors without the full kit+badge
     * identity shape (e.g. CompetitionSpoofEntrantService's synthetic club
     * snapshots). Same contrast rule as randomKitVariant()'s own pick.
     *
     * @return string[] [primary, secondary]
     */
    public function pickColorPair(): array
    {
        $kitColors = array_map(static fn (KitColor $c) => $c->value, KitColor::cases());
        $primary   = $kitColors[array_rand($kitColors)];
        $secondary = $this->pickContrastingKitColor($primary, $kitColors);

        return [$primary, $secondary];
    }

    public function playingStyleForTier(int $tier): string
    {
        $styles = ['POSSESSION', 'DIRECT', 'COUNTER', 'HIGH_PRESS'];
        return $styles[array_rand($styles)];
    }

    private function financialApproachForTier(int $tier): string
    {
        // Lower tier numbers (elite) lean SPECULATIVE; higher tier numbers (lower league) lean CONSERVATIVE
        if ($tier <= 2) {
            $options = ['SPECULATIVE', 'SPECULATIVE', 'SPECULATIVE', 'BALANCED', 'BALANCED', 'CONSERVATIVE'];
        } elseif ($tier <= 5) {
            $options = ['SPECULATIVE', 'BALANCED', 'BALANCED', 'BALANCED', 'CONSERVATIVE', 'CONSERVATIVE'];
        } else {
            $options = ['SPECULATIVE', 'BALANCED', 'CONSERVATIVE', 'CONSERVATIVE', 'CONSERVATIVE', 'CONSERVATIVE'];
        }
        return $options[array_rand($options)];
    }
}
