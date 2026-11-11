<?php

declare(strict_types=1);

namespace App\Service\Location\CountryEnrichment;

use App\Entity\Location\CountryProfile;
use App\Service\Ai\AiUnavailableException;
use App\Service\Ai\AnthropicJsonClient;

/**
 * Asks the Claude Messages API for a country's bounding box, land band and reference metadata.
 *
 * PROMPT, SCHEMA AND PARSING ONLY — the HTTP is AnthropicJsonClient's, shared with town
 * enrichment and town suggestion, because auth, the pinned API version and the
 * structured-output request shape must have exactly one implementation.
 *
 * EVERY SCHEMA FIELD IS REQUIRED AND NULLABLE, the combination that matters: required stops
 * the model silently dropping `max_lng`, which would land as a bounding box that looks fine
 * until it starts rejecting real coordinates; nullable lets it decline a field honestly,
 * which the validator reads as "not supplied" rather than as a rejection.
 *
 * THE BOUNDING BOX IS THE POINT OF THIS CLASS. EnrichmentValidator checks every AI-supplied
 * town coordinate against CountryProfile::$bbox, and a country without one falls back to a
 * globe-range check that cannot tell Birmingham, England from Birmingham, Alabama. Nothing in
 * this codebase populated that box except an operator typing JSON into a textarea.
 *
 * THE LAND BAND IS ASKED FOR IN POUNDS and multiplied by 100 here, exactly as
 * ClaudeLocationEnricher does and for the same reason: a model asked for pence loses track of
 * the factor of 100, and an answer wrong by 100x is inside a plausible band for a different
 * country, so it would be accepted rather than caught.
 *
 * EFFORT IS LOW. This is recall against a fixed schema, not reasoning — and thinking tokens
 * come out of the same max_tokens as the answer, which is what truncated a third of the town
 * suggestion batches before they were sized down.
 */
final class ClaudeCountryProfileEnricher implements CountryProfileEnricherInterface
{
    /** Bumped on ANY change to the prompt text or the schema below. */
    private const PROMPT_VERSION = 'country-claude-v1';

    /** Nine short scalars and a four-number box. Generous, with room for a little thinking. */
    private const MAX_TOKENS = 2_048;

    /**
     * Well under the 60s an nginx in front of this will wait.
     *
     * The admin button is SYNCHRONOUS, so a provider that has stopped answering must return a
     * flash rather than a dead page. The town pull learned this the expensive way: one call
     * for 300 names ran 60-180s while the proxy gave up at 60, so the operator saw a failure
     * for work that was often still running. A reply this small has no business taking 30s.
     */
    private const TIMEOUT_SECONDS = 30;

    public function __construct(private readonly AnthropicJsonClient $client) {}

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->client->model();
    }

    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function enrich(CountryProfile $country): CountryProfileFacts
    {
        if (!$this->client->isConfigured()) {
            throw new CountryEnrichmentUnavailableException(UnconfiguredCountryProfileEnricher::MESSAGE);
        }

        try {
            $response = $this->client->sendJson(
                $this->prompt($country),
                self::schema(),
                self::MAX_TOKENS,
                \sprintf('country profile for %s', $country->getName()),
                self::TIMEOUT_SECONDS,
                AnthropicJsonClient::EFFORT_LOW,
            );
        } catch (AiUnavailableException $e) {
            throw new CountryEnrichmentUnavailableException($e->getMessage(), previous: $e);
        }

        $decoded = $response->data;

        return new CountryProfileFacts(
            bbox: $this->bbox($decoded),
            landPenceMin: $this->poundsToPence($decoded['land_value_gbp_min'] ?? null),
            landPenceMax: $this->poundsToPence($decoded['land_value_gbp_max'] ?? null),
            isoAlpha3: $this->stringOrNull($decoded['iso_alpha3'] ?? null),
            continent: $this->stringOrNull($decoded['continent'] ?? null),
            defaultTimezone: $this->stringOrNull($decoded['default_timezone'] ?? null),
            currencyCode: $this->stringOrNull($decoded['currency_code'] ?? null),
            landPriceCurrencyCode: $this->stringOrNull($decoded['land_price_currency_code'] ?? null),
            distanceUnit: $this->stringOrNull($decoded['distance_unit'] ?? null),
            raw: $decoded,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
        );
    }

    /**
     * The schema the reply is held to.
     *
     * The box is FOUR SEPARATE NUMBERS rather than a nested object, deliberately. A nested
     * object gives the model somewhere to put a partial answer that still satisfies the
     * schema; four required-and-nullable siblings mean a half-supplied box arrives visibly
     * half-supplied and the validator refuses the whole of it.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $number = static fn (string $description): array => [
            'type'        => ['number', 'null'],
            'description' => $description,
        ];

        $string = static fn (string $description): array => [
            'type'        => ['string', 'null'],
            'description' => $description,
        ];

        return [
            'type'       => 'object',
            'properties' => [
                'min_lat' => $number('Southernmost latitude of the country, decimal degrees, WGS84.'),
                'max_lat' => $number('Northernmost latitude.'),
                'min_lng' => $number('Westernmost longitude.'),
                'max_lng' => $number('Easternmost longitude.'),
                'land_value_gbp_min' => $number(
                    'The CHEAPEST plausible cost in POUNDS STERLING of a football-stadium-sized '
                    . 'plot of developable land anywhere in this country — a small, inexpensive town.',
                ),
                'land_value_gbp_max' => $number(
                    'The DEAREST plausible cost in POUNDS STERLING of the same plot — the most '
                    . 'expensive city in the country.',
                ),
                'iso_alpha3'               => $string('ISO 3166-1 alpha-3 code, e.g. GBR.'),
                'continent'                => $string('One of: Africa, Antarctica, Asia, Europe, North America, Oceania, South America.'),
                'default_timezone'         => $string('IANA zone for the most populous part of the country, e.g. Europe/London.'),
                'currency_code'            => $string('ISO 4217 code of the national currency, e.g. GBP.'),
                'land_price_currency_code' => $string('ISO 4217 code land is normally quoted in locally. Usually the same as currency_code.'),
                'distance_unit'            => $string('Either km or mi, whichever everyday distances are given in.'),
            ],
            'required' => [
                'min_lat', 'max_lat', 'min_lng', 'max_lng',
                'land_value_gbp_min', 'land_value_gbp_max',
                'iso_alpha3', 'continent', 'default_timezone',
                'currency_code', 'land_price_currency_code', 'distance_unit',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * The prompt.
     *
     * SAYS WHAT THE BOX IS FOR, because the useful answer depends on it. A box that is right
     * for a map legend is the wrong answer here: this one is a sanity check on individual town
     * coordinates, so it must contain every inhabited part of the country — including
     * overseas territory people actually live in — and a box drawn around the mainland alone
     * would start refusing real towns.
     *
     * Gives the model what is already on record, the way ClaudeLocationEnricher does, so the
     * answer is consistent with the row rather than a second unrelated opinion about it.
     */
    private function prompt(CountryProfile $country): string
    {
        $known = [
            \sprintf('Country: %s', $country->getName()),
            \sprintf('ISO 3166-1 alpha-2 code: %s', $country->getCode()),
        ];

        if ($country->getIsoAlpha3() !== null) {
            $known[] = \sprintf('ISO alpha-3 (already on record): %s', $country->getIsoAlpha3());
        }

        if ($country->getCurrencyCode() !== null) {
            $known[] = \sprintf('Currency (already on record): %s', $country->getCurrencyCode());
        }

        $knownLines = implode("\n", array_map(static fn (string $l): string => '- ' . $l, $known));

        return <<<PROMPT
            You are supplying reference facts about a country for a football management game.

            {$knownLines}

            Return:

            1. min_lat / max_lat / min_lng / max_lng — a bounding box around the country.

               THIS BOX IS USED TO CHECK INDIVIDUAL TOWN COORDINATES, so it must contain every
               inhabited part of the country, including any populated islands or overseas
               regions that are genuinely part of it. A box drawn around only the mainland
               would cause real towns to be rejected. Err on the side of slightly too large.
               Longitudes are -180 to 180 and latitudes -90 to 90.

            2. land_value_gbp_min / land_value_gbp_max — in POUNDS STERLING, the cheapest and
               dearest plausible cost of buying a football-stadium-sized plot of developable
               land in this country. The whole site, not a rate per acre. The minimum is a
               small cheap town; the maximum is the most expensive city. These two bracket
               every town in the country, so spread them generously — they are used to catch a
               valuation that is wrong by orders of magnitude, not to price anything.

            3. iso_alpha3, continent, default_timezone, currency_code,
               land_price_currency_code, distance_unit — plain reference facts.

            ACCURACY BAR: a box and a band that are usable as sanity checks, not a survey. Both
            are deliberately generous: being a little too wide costs nothing, while being too
            narrow silently rejects real data.

            Return null for any single field you genuinely cannot supply. A null is useful; a
            guess you would not stand behind is not.
            PROMPT;
    }

    /**
     * The four numbers as a box, or null.
     *
     * ALL FOUR OR NOTHING. A box missing an edge is not a smaller box, it is an unusable one,
     * and the validator would have to invent the missing side to use it.
     *
     * @param  array<string, mixed> $decoded
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}|null
     */
    private function bbox(array $decoded): ?array
    {
        $values = [];

        foreach (['minLat' => 'min_lat', 'maxLat' => 'max_lat', 'minLng' => 'min_lng', 'maxLng' => 'max_lng'] as $key => $wire) {
            $raw = $decoded[$wire] ?? null;

            if (!\is_int($raw) && !\is_float($raw)) {
                return null;
            }

            $values[$key] = (float) $raw;
        }

        return $values;
    }

    private function poundsToPence(mixed $v): ?int
    {
        if (!\is_int($v) && !\is_float($v)) {
            return null;
        }

        return (int) round(((float) $v) * 100);
    }

    private function stringOrNull(mixed $v): ?string
    {
        if (!\is_string($v)) {
            return null;
        }

        $trimmed = trim($v);

        return $trimmed === '' ? null : $trimmed;
    }
}
