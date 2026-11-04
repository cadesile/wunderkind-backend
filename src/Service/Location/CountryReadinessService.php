<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Enum\Country;
use App\Enum\Location\EnrichmentStatus;
use App\Repository\LeagueRepository;
use App\Repository\Location\LocationRepository;
use App\Repository\StarterConfigRepository;
use App\Service\CountryContent\CountryContentRegistry;
use Doctrine\DBAL\Connection;

/**
 * Whether a country's data is complete enough for its locations to go live.
 *
 * The user's requirement was "each country needs its tier structure before its locations can
 * go live". Nothing new is invented to express that: the league pyramid
 * (LeagueService::generateLeaguesForCountry(), eight tiers), the per-country ability ranges
 * (StarterConfig::$leagueAbilityRanges) and the club-naming content
 * (CountryContentRegistry::missingCategories()) all already exist. This service only reads
 * them, adds the location and enrichment checks, and returns a single verdict.
 *
 * Enforced in three places, so a half-built country cannot leak out:
 *   - CountryProfileCrudController refuses to set status LIVE when not ready (flash + early
 *     return, the LanguageCrudController::deleteEntity() idiom).
 *   - GET /api/locations only returns locations of LIVE countries.
 *   - app:country:bootstrap and app:country:check print the verdict.
 *
 * NOTE ON `places`: CountryContentRegistry::missingCategories() still reports a `places`
 * category, but place data now lives in the `location` table, so that one entry is dropped
 * here and replaced by the real location checks below. The registry's other categories
 * (suffixes, stadium formats) are still authored in PHP and still count.
 *
 * Deliberately NOT a check on StarterConfig::$enabledCountries. Generation-capable,
 * data-ready and player-visible are three separate notions, and
 * docs/world-generation/adding-a-country.md is emphatic that conflating them is the bug this
 * whole area keeps re-learning.
 */
final class CountryReadinessService
{
    /** Tiers LeagueService::generateLeaguesForCountry() creates. */
    public const EXPECTED_LEAGUE_TIERS = 8;

    /** Registry category that is now satisfied by the `location` table, not by PHP. */
    private const DB_BACKED_CATEGORY = 'places';

    public function __construct(
        private readonly LeagueRepository        $leagueRepository,
        private readonly LocationRepository      $locationRepository,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly Connection              $connection,
    ) {}

    /**
     * @return array{
     *     country: string,
     *     ready: bool,
     *     checks: list<array{key: string, ok: bool, detail: string}>,
     *     blocking: list<string>
     * }
     */
    public function evaluate(string $countryCode): array
    {
        $code   = strtoupper(trim($countryCode));
        $checks = [];

        $checks[] = $this->leagueCheck($code);
        $checks[] = $this->abilityRangeCheck($code);
        $checks[] = $this->contentCheck($code);
        $checks[] = $this->locationCheck($code);
        $checks[] = $this->plotCheck($code);
        $checks[] = $this->enrichmentCheck($code);
        $checks[] = $this->sellableCheck($code);

        $blocking = array_values(array_map(
            static fn (array $check): string => $check['key'],
            array_filter($checks, static fn (array $check): bool => !$check['ok']),
        ));

        return [
            'country'  => $code,
            'ready'    => $blocking === [],
            'checks'   => $checks,
            'blocking' => $blocking,
        ];
    }

    public function isReady(string $countryCode): bool
    {
        return $this->evaluate($countryCode)['ready'];
    }

    /** @return array{key: string, ok: bool, detail: string} */
    /**
     * Priced, placed towns a country needs before players may be let into it.
     *
     * Twenty-five is a judgement, not a derivation: enough that the land list is worth browsing
     * and a player has real choice on day one, low enough that a country does not need its whole
     * catalogue enriched before it can open. The rest arrive lazily as players find them.
     */
    public const MIN_SELLABLE_LOCATIONS = 25;

    private function leagueCheck(string $code): array
    {
        $count = count($this->leagueRepository->findByCountry($code));

        return [
            'key'    => 'leagues',
            'ok'     => $count >= self::EXPECTED_LEAGUE_TIERS,
            'detail' => sprintf('%d of %d league tiers exist', $count, self::EXPECTED_LEAGUE_TIERS),
        ];
    }

    /** @return array{key: string, ok: bool, detail: string} */
    private function abilityRangeCheck(string $code): array
    {
        $ranges = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();
        $has    = isset($ranges[$code]) && is_array($ranges[$code]) && $ranges[$code] !== [];

        return [
            'key'    => 'ability_ranges',
            'ok'     => $has,
            'detail' => $has
                ? 'StarterConfig::$leagueAbilityRanges has an entry'
                : 'no StarterConfig::$leagueAbilityRanges entry — app:country:bootstrap seeds it',
        ];
    }

    /** @return array{key: string, ok: bool, detail: string} */
    private function contentCheck(string $code): array
    {
        $country = Country::tryFrom($code);

        if ($country === null) {
            return [
                'key'    => 'content',
                'ok'     => false,
                'detail' => "'{$code}' is not an App\\Enum\\Country case — a new country still needs an enum case",
            ];
        }

        // `places` is excluded: it moved to the `location` table. See the class docblock.
        $missing = array_values(array_filter(
            CountryContentRegistry::missingCategories($country),
            static fn (string $category): bool => $category !== self::DB_BACKED_CATEGORY,
        ));

        return [
            'key'    => 'content',
            'ok'     => $missing === [],
            'detail' => $missing === []
                ? 'club-naming content authored'
                : 'missing from CountryContentRegistry: ' . implode(', ', $missing),
        ];
    }

    /** @return array{key: string, ok: bool, detail: string} */
    private function locationCheck(string $code): array
    {
        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM location WHERE country_code = :code AND status = 'active'",
            ['code' => $code],
        );

        return [
            'key'    => 'locations',
            'ok'     => $total > 0,
            'detail' => sprintf('%d active location(s)', $total),
        ];
    }

    /** @return array{key: string, ok: bool, detail: string} */
    private function plotCheck(string $code): array
    {
        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
               FROM land_plot p
               JOIN location l ON l.id = p.location_id
              WHERE l.country_code = :code AND p.status = 'active' AND l.status = 'active'",
            ['code' => $code],
        );

        return [
            'key'    => 'plots',
            'ok'     => $total > 0,
            'detail' => sprintf('%d active plot(s)', $total),
        ];
    }

    /**
     * No location may still be awaiting enrichment, or sitting in a bucket needing a human.
     *
     * This is the check that makes the whole gate worth having — without it a country goes
     * live with half its towns unpriced, and a player sees plots they cannot buy.
     *
     * @return array{key: string, ok: bool, detail: string}
     */
    private function enrichmentCheck(string $code): array
    {
        $counts = $this->locationRepository->countByEnrichmentStatus($code);

        // PENDING AND QUEUED ARE NOT FAILURES. They used to block, which was right when every
        // town in the catalogue was curated and enriched in one sweep. The catalogue now also
        // holds thousands of bare imported names waiting for lazy enrichment — a town is
        // enriched the first time a player opens it — so a permanent backlog is the design, and
        // requiring it to be empty would mean no country could ever go Live again.
        //
        // NEEDS_REVIEW and FAILED still block: those are rows a human has to look at, and
        // shipping a country with unreviewed valuations is the thing this gate exists to stop.
        $outstanding = [];
        foreach ([EnrichmentStatus::NEEDS_REVIEW, EnrichmentStatus::FAILED] as $status) {
            $n = $counts[$status->value] ?? 0;
            if ($n > 0) {
                $outstanding[] = "{$n} {$status->value}";
            }
        }

        $backlog = ($counts[EnrichmentStatus::PENDING->value] ?? 0)
            + ($counts[EnrichmentStatus::QUEUED->value] ?? 0);

        return [
            'key'    => 'enrichment',
            'ok'     => $outstanding === [],
            'detail' => $outstanding === []
                ? ($backlog > 0
                    ? sprintf('nothing needs review (%d awaiting lazy enrichment, which is fine)', $backlog)
                    : 'every location enriched')
                : 'needs a human: ' . implode(', ', $outstanding),
        ];
    }

    /**
     * Enough towns must actually carry the two values a sale needs.
     *
     * A MINIMUM, NOT A TOTAL. This asked for EVERY active location to be priced, which was
     * right while the catalogue was 865 curated towns enriched in one pass. It now also holds
     * thousands of bare imported names that are priced lazily, on first view — so "all of them"
     * is a bar no country will ever clear, and the question worth asking became "is there
     * enough here for a player to actually buy something on day one?".
     *
     * Separate from the enrichment check on purpose: a row can be marked ENRICHED by a provider
     * that returned a population but no usable coordinate, or be hand-created in the admin and
     * never enriched at all. This asks the data directly rather than trusting the status column.
     *
     * @return array{key: string, ok: bool, detail: string}
     */
    private function sellableCheck(string $code): array
    {
        $sellable = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
               FROM location
              WHERE country_code = :code
                AND status = 'active'
                AND lat IS NOT NULL AND lng IS NOT NULL AND land_pence IS NOT NULL",
            ['code' => $code],
        );

        return [
            'key'    => 'sellable',
            'ok'     => $sellable >= self::MIN_SELLABLE_LOCATIONS,
            'detail' => sprintf(
                '%d location(s) priced and placed, of %d needed to open',
                $sellable,
                self::MIN_SELLABLE_LOCATIONS,
            ),
        ];
    }
}
