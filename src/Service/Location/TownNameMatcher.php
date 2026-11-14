<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Enum\Country;
use App\Service\ClubNameNormalizer;
use App\Service\CountryContent\CountryContentRegistry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Recovering the town a club was named after, from its name alone.
 *
 * EXTRACTED FROM NpcClubLocationLinker, VERBATIM, and that is the whole point of it existing as
 * a class. A second consumer appeared — ClubSaleValuationService has to price the land under a
 * player club founded before the land economy, which has no `home_location_id` and therefore no
 * link but its name — and the alternative was a second copy of the rules below. The linker's own
 * docblock already refuses a SQL reimplementation for exactly that reason; a PHP copy would be
 * the same mistake with a shorter fuse, because the two would be free to drift and nothing would
 * fail when they did.
 *
 * THE MATCHING RULE, and why it is this and not a LIKE '%town%'.
 * A generated club name is exactly one space joining a town and an affix: town-leading in 19
 * countries, affix-leading in the six in CountryContentRegistry::SUFFIX_LEADS_NAME (PL, BE, SA,
 * CO, EG, TN — Legia Lodz, Gornik Gdynia). The town is always a whole, verbatim, space-delimited
 * token. So:
 *
 *   1. longest whole-token match in the position the country PUTS the town, then
 *   2. longest whole-token match anywhere.
 *
 * THE POSITION IS PER COUNTRY, and getting that wrong is not theoretical — it shipped. An
 * earlier cut always preferred the PREFIX, which is right for the 19 town-leading countries and
 * exactly backwards for the six affix-leading ones. It mis-linked the Saudi club "Al Riyadh
 * Hail" to Riyadh instead of Hail, because `riyadh` is a longer token than `hail` and neither is
 * a prefix.
 *
 * Three hazards make the obvious alternatives wrong:
 *
 *   - A SUBSTRING IS NOT A TOKEN. Manchester contains Chester; Enschede contains Ede (NL);
 *     Calabar contains Aba (NG); Las Palmas contains Palma (ES). Token matching rejects all of
 *     them, LIKE '%town%' does not.
 *   - SOME AFFIXES ARE THEMSELVES TOWNS, IN BOTH DIRECTIONS. Burgos is an ES generic suffix and
 *     an ES town; so are Bari and Palermo (IT prestige), Amiens (FR generic), Covilha (PT
 *     generic) — so "Metz Amiens" matches two towns and the LONGER one is wrong. The mirror case
 *     is "Al Riyadh Hail" in suffix-leading SA, where the longer token is in the AFFIX. Position
 *     outranks length; which position depends on the country.
 *   - COMPOUND TOWNS CONTAIN SHORTER ONES. Aparecida de Goiania contains Goiania; Richmond Hill
 *     contains Richmond. Longest positional match picks the compound, correctly.
 *
 * MATCHED IN PHP, NOT SQL. The comparison key is ClubNameNormalizer::normalize() — the same
 * function that produced `location.town_normalised` and `club.name_normalised`. Reproducing it
 * in SQL would need the `unaccent` extension and would be a SECOND definition of the
 * normalisation rules; the normalizer's own docblock already warns that the client mirrors it
 * too. Two passes over a few thousand short strings costs nothing.
 *
 * A PLAYER CLUB IS A WEAKER CASE THAN AN NPC ONE, and the caller must treat it as such. An NPC
 * name was composed by NpcClubGenerationService from the pools, so the town is known to be in
 * there. A player typed theirs. It usually follows the same convention, because the client
 * composes it from the same place/suffix pools, but nothing enforces that — so a miss here is
 * ordinary, and a hit is evidence rather than proof. ClubSaleValuationService records
 * `landBasis: 'name_match'` precisely so an operator can see the figure rests on this.
 */
final class TownNameMatcher
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * The one-club convenience path: normalise a club's name and match it inside its country.
     *
     * Fetches that country's towns per call, which is right for the single-club admin case and
     * wrong for a sweep — a sweep should call townsByCountry() once and match() per club, the way
     * NpcClubLocationLinker::link() does, or it re-reads the whole country per row.
     *
     * @return array<string, mixed>|null
     */
    public function matchClubName(string $clubName, ?string $countryCode): ?array
    {
        $countryCode = strtoupper(trim((string) $countryCode));

        if ($countryCode === '') {
            return null;
        }

        $towns = $this->townsByCountry([$countryCode])[$countryCode] ?? [];

        if ($towns === []) {
            return null;
        }

        return $this->match(
            ClubNameNormalizer::normalize($clubName),
            $towns,
            $this->suffixLeads($countryCode),
        );
    }

    /**
     * Whether this country writes its club names as "{affix} {town}" rather than "{town} {affix}".
     *
     * Six do: PL, BE, SA, CO, EG, TN. Polish is uniformly like this (Legia Warszawa, Gornik
     * Zabrze) and composing it the other way round produces names no Polish speaker would
     * recognise — which is why NpcClubGenerationService asks the same question when it BUILDS
     * the name. This reads the identical source, so the two cannot disagree.
     */
    public function suffixLeads(string $countryCode): bool
    {
        $country = Country::tryFrom(strtoupper(trim($countryCode)));

        return $country !== null && CountryContentRegistry::suffixLeadsName($country);
    }

    /**
     * The rule, applied to one normalised club name against one country's towns.
     *
     * Towns must arrive sorted longest-normalised-name first — townsByCountry() does that — so
     * the first positional hit is the longest one and the first token hit is the longest token,
     * and no scoring pass is needed.
     *
     * @param list<array<string, mixed>> $towns
     *
     * @return array<string, mixed>|null
     */
    public function match(string $clubNorm, array $towns, bool $suffixLeads): ?array
    {
        $tokenHit = null;

        foreach ($towns as $town) {
            $t = (string) $town['town_normalised'];

            if ($t === '') {
                continue;
            }

            // PASS 1: the position this country actually puts the town in. Wins outright, so
            // return immediately rather than scoring — and it is what stops a longer town name
            // sitting in the AFFIX from beating the real one.
            $positional = $suffixLeads
                ? str_ends_with($clubNorm, ' ' . $t)
                : str_starts_with($clubNorm, $t . ' ');

            if ($clubNorm === $t || $positional) {
                return $town;
            }

            // PASS 2: anywhere in the name, longest first. Reached when the name does not follow
            // its country's convention at all — an imported or hand-edited club, say.
            if ($tokenHit === null
                && (str_ends_with($clubNorm, ' ' . $t) || str_contains($clubNorm, ' ' . $t . ' '))) {
                $tokenHit = $town;
            }
        }

        return $tokenHit;
    }

    /**
     * Active towns per country, longest normalised name first — which is what lets match()
     * return on its first hit instead of scoring every candidate.
     *
     * `land_pence` is selected although the NPC linker has no use for it: the valuation consumer
     * prices the plot from the matched row, and a second query to fetch one column off a row
     * already in hand would be the thing this class exists to avoid.
     *
     * @param list<string>|null $countryCodes null means every country
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function townsByCountry(?array $countryCodes = null): array
    {
        $sql    = "SELECT id, country_code, town, town_normalised, region, city_size,
                          population, is_capital, land_pence
                     FROM location WHERE status = 'active'";
        $params = [];
        $types  = [];

        if ($countryCodes !== null && $countryCodes !== []) {
            $sql .= ' AND country_code IN (:countries)';
            $params['countries'] = $countryCodes;
            $types['countries']  = ArrayParameterType::STRING;
        }

        $sql .= ' ORDER BY country_code, length(town_normalised) DESC, town ASC';

        $out = [];

        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $out[(string) $row['country_code']][] = $row;
        }

        return $out;
    }
}
