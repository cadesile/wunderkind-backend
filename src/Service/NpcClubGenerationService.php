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
use App\Repository\Location\CountryProfileRepository;
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
    private const TRAINING_SLUGS = ['training_pitch', 'strength_suite'];
    private const STANDS_SLUGS   = ['north_stand', 'south_stand', 'east_stand', 'west_stand'];

    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly FacilityTemplateRepository  $facilityTemplateRepo,
        private readonly NpcClubRepository           $npcClubRepo,
        private readonly LeagueService               $leagueService,
        private readonly GameConfigRepository        $gameConfigRepository,
        private readonly LocationCatalogueService    $locationCatalogue,
        private readonly CountryProfileRepository     $countryProfileRepository,
    ) {}

    /**
     * How deep this country's pyramid is meant to be.
     *
     * Read from the profile's declared intent rather than counted from the league rows:
     * clubs are often generated while a structure is still being built, and rescaling them
     * against a half-finished count would bake in the wrong reputations and balances.
     * Falls back to the full eight for a country with no profile yet.
     */
    private function tierCountFor(string $country): int
    {
        return $this->countryProfileRepository->findByCode(strtoupper($country))?->getLeagueTierCount() ?? 8;
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
            CountryContentRegistry::prestigeSuffixes($country),
            CountryContentRegistry::genericSuffixes($country),
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
        $classifiedPlaces = $this->classifyPlaces($placeData);
        $prestigeSuffixes = ($countryEnum !== null ? CountryContentRegistry::prestigeSuffixes($countryEnum) : []) ?: ['FC'];
        $genericSuffixes  = ($countryEnum !== null ? CountryContentRegistry::genericSuffixes($countryEnum) : []) ?: ['FC'];
        $weights          = $this->gameConfigRepository->getConfig()->getNpcClubSizeWeightsForTier($tier, $tierCount);
        $suffixLeads      = $countryEnum !== null && CountryContentRegistry::suffixLeadsName($countryEnum);
        $usedNames        = [];
        $clubs            = [];

        if ($deleteExisting) {
            $this->npcClubRepo->deleteByCountryAndTier($country, $tier);
        }

        for ($i = 0; $i < $count; $i++) {
            [$name, $place] = $this->generateName($classifiedPlaces, $usedNames, $prestigeSuffixes, $genericSuffixes, $weights, $suffixLeads);
            $usedNames[]    = $name;
            $citySize       = $place['city_size'];
            $reputation     = $this->reputationForTier($tier, $citySize, $tierCount);
            $balance        = $this->balanceForTier($tier, $citySize, $tierCount);
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
        return $clubs;
    }

    /**
     * Weighted random pick across classified places. Weight for a place =
     * (tier's % for its city_size bucket) / (count of places in that bucket),
     * so weight is spread evenly within a bucket.
     *
     * @param array<array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}> $classifiedPlaces
     * @param array{big:float,medium:float,small:float} $weights
     * @return array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}
     */
    public function pickWeightedPlace(array $classifiedPlaces, array $weights): array
    {
        $bucketCounts = ['big' => 0, 'medium' => 0, 'small' => 0];
        foreach ($classifiedPlaces as $place) {
            $bucketCounts[strtolower($place['city_size']->value)]++;
        }

        $cumulative = [];
        $total = 0.0;
        foreach ($classifiedPlaces as $i => $place) {
            $bucket = strtolower($place['city_size']->value);
            $count  = max(1, $bucketCounts[$bucket]);
            $weight = max(0.0001, (float) ($weights[$bucket] ?? 0)) / $count;
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
     * @return array{0:string,1:array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}}
     */
    private function generateName(array $classifiedPlaces, array $usedNames, array $prestigeSuffixes, array $genericSuffixes, array $weights, bool $suffixLeads = false): array
    {
        $attempts = 0;
        do {
            $place  = $this->pickWeightedPlace($classifiedPlaces, $weights);
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
        $prestigeSuffixes = ($countryEnum !== null ? CountryContentRegistry::prestigeSuffixes($countryEnum) : []) ?: ['FC'];
        $genericSuffixes  = ($countryEnum !== null ? CountryContentRegistry::genericSuffixes($countryEnum) : []) ?: ['FC'];
        $tierCount        = $this->tierCountFor($countryCode);
        $weights          = $this->gameConfigRepository->getConfig()->getNpcClubSizeWeightsForTier(max(1, min($tierCount, $tier)), $tierCount);

        $suffixLeads = $countryEnum !== null && CountryContentRegistry::suffixLeadsName($countryEnum);

        [$name] = $this->generateName($classifiedPlaces, $usedNames, $prestigeSuffixes, $genericSuffixes, $weights, $suffixLeads);

        return $name;
    }

    public function generateStadiumName(string $place, string $country): string
    {
        $countryEnum = Country::tryFrom($country);
        $formats = ($countryEnum !== null ? CountryContentRegistry::stadiumFormats($countryEnum) : []) ?: ['%s Stadium', '%s Ground', 'The %s Arena'];
        $format  = $formats[array_rand($formats)];
        return sprintf($format, $place);
    }

    /** @return array{0:int,1:int} */
    public function skewRange(int $min, int $max, \App\Enum\CitySize $citySize): array
    {
        $span = $max - $min;

        return match ($citySize) {
            \App\Enum\CitySize::BIG    => [min($max, $min + (int) round($span * 0.33)), $max],
            \App\Enum\CitySize::SMALL  => [$min, max($min, $min + (int) round($span * 0.66))],
            \App\Enum\CitySize::MEDIUM => [$min, $max],
        };
    }

    private function reputationForTier(int $tier, \App\Enum\CitySize $citySize, int $tierCount = 8): int
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
        return random_int($min, $max);
    }

    private function balanceForTier(int $tier, \App\Enum\CitySize $citySize, int $tierCount = 8): int
    {
        $range = $this->gameConfigRepository->getConfig()->getNpcClubBalanceRangeForTier($tier, $tierCount);
        $min   = max(0, (int) $range['min']);
        $max   = max($min, (int) $range['max']);
        [$skewMin, $skewMax] = $this->skewRange($min, $max, $citySize);
        return random_int($skewMin, $skewMax);
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
