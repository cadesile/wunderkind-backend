<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Enum\Country;
use App\Enum\Location\EnrichmentStatus;
use App\Repository\LeagueRepository;
use App\Repository\Location\LocationRepository;
use App\Repository\StarterConfigRepository;
use App\Service\CountryContent\CountryNamingContentProvider;
use Doctrine\DBAL\Connection;

/**
 * Whether a country's data is complete enough for its locations to go live.
 *
 * The user's requirement was "each country needs its tier structure before its locations can
 * go live". Nothing new is invented to express that: the league pyramid
 * (LeagueService::generateLeaguesForCountry(), eight tiers), the per-country ability ranges
 * (StarterConfig::$leagueAbilityRanges) and the club-naming content
 * (CountryNamingContentProvider::missingCategories()) all already exist. This service only
 * reads them, adds the location and enrichment checks, and returns a single verdict.
 *
 * Enforced everywhere a country can reach LIVE, so a half-built one cannot leak out. All
 * three write paths go through CountryGoLiveService rather than setting the status directly:
 *   - CountryProfileCrudController refuses to set status LIVE when not ready (flash + early
 *     return, the LanguageCrudController::deleteEntity() idiom).
 *   - The locations dashboard's GO LIVE button re-checks on POST — an enabled button is not
 *     a check, since a stale page can click one that was valid when it rendered.
 *   - app:country:bootstrap --enable refuses the same way. It used to set visibility without
 *     asking, which was harmless while that was a separate list and is not now.
 * Plus the read side: GET /api/locations only returns locations of LIVE countries, and
 * app:country:check prints the verdict without changing anything.
 *
 * NOTE ON `places`: place data lives in the `location` table and is covered by the location
 * checks below, so the content check never asks about it —
 * CountryNamingContentProvider::missingCategories() answers about the three naming categories
 * only. (CountryContentRegistry's own static of that name still reports `places`; it answers
 * a different question, about what is authored in PHP, and its tests assert exactly that.)
 *
 * THE CONTENT CHECK IS NO LONGER A DEPLOY-SHAPED BLOCKER. Suffixes and stadium formats can be
 * authored from the locations dashboard and stored on country_profile, with the PHP consts as
 * the fallback — which is why this reads the provider rather than the registry.
 *
 * WHAT THIS DOES AND DOES NOT DECIDE. It answers "is the data ready", not "has an operator
 * chosen to open this country" — passing every check does not make a country live, it only
 * makes it promotable. The two notions that remain separate are this one and
 * Country::isGenerationCapable(), which is fixed at deploy.
 *
 * (It used to be three. StarterConfig::$enabledCountries was a third, hand-ticked axis for
 * player-visibility; it is gone, and LIVE now carries that meaning —
 * CountryProfileRepository::liveCountryCodes(). The warning in
 * docs/world-generation/adding-a-country.md about conflating these is still worth heeding for
 * the two that are left.)
 */
final class CountryReadinessService
{
    /**
     * The deepest pyramid the per-tier config tables describe.
     *
     * NO LONGER THE READINESS BAR. A country may deliberately have fewer tiers — see
     * leagueCheck() — and requiring eight here meant an admin could pick three on the
     * generate form and then find the country permanently unable to go Live, with the gate
     * silently overruling the choice they had just made.
     */
    public const MAX_LEAGUE_TIERS = 8;

    public function __construct(
        private readonly LeagueRepository        $leagueRepository,
        private readonly LocationRepository      $locationRepository,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly Connection              $connection,
        // Appended rather than slotted in beside the other content dependency: several tests
        // build this service positionally, and inserting an argument mid-list breaks every one
        // of them for no gain.
        private readonly CountryNamingContentProvider $namingContent,
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

        // At least one. The gate's job is to catch a country with NO pyramid at all —
        // which cannot serve a pack, place a founder, or run a season. How deep the
        // pyramid goes beyond that is a decision made on the generate form, and this is
        // not the place to second-guess it.
        return [
            'key'    => 'leagues',
            'ok'     => $count >= 1,
            'detail' => $count === 0
                ? 'no league tiers exist'
                : sprintf('%d league tier(s) exist', $count),
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

        // The provider's answer, not the registry's: it never reports `places`, and it counts
        // an admin override as authored.
        $missing = $this->namingContent->missingCategories($country);

        return [
            'key'    => 'content',
            'ok'     => $missing === [],
            'detail' => $missing === []
                ? 'club-naming content authored'
                // NAMES WHERE TO FIX IT, which is the whole point of the change. This used to
                // read "missing from CountryContentRegistry", pointing an operator at a PHP
                // file they could not edit and a deploy they could not make.
                : 'no club-naming content: ' . implode(', ', $missing) . ' — set it on this screen',
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
