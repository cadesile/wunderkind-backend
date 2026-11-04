<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

use App\Entity\Location\Location;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the Claude Messages API for the geography the seeded data does not have.
 *
 * RAW HTTP OVER THE SHARED HttpClientInterface, no vendor SDK. The request is one POST with a
 * JSON body; `YouTubeFeedService` and `SocialPostingService` already talk to external APIs this
 * way, and a new Composer dependency for one endpoint is not worth the supply-chain surface.
 *
 * STRUCTURED OUTPUTS, which is the single most important choice in this class. `output_config.
 * format` with a JSON schema makes the response schema-valid BY CONSTRUCTION rather than
 * parsed hopefully — and the dominant failure mode of a free-text prompt here is exactly the
 * one that costs most: a reply that reads perfectly but silently omits `lng`, which then lands
 * as a half-enriched row that looks fine until a rival distance is computed from it.
 *
 * THE MODEL AND KEY ARE INJECTED FROM ENV, never hardcoded, so switching model is a deploy
 * variable rather than a code change — and `enrichment_provider` / `enrichment_model` are
 * stored on every row this produces, so a change is visible per location rather than guessed
 * at from timestamps.
 *
 * IT DOES NOT VALIDATE AND DOES NOT WRITE. Everything it returns is untrusted: a coordinate may
 * be in the Atlantic and a valuation may be out by three orders of magnitude. EnrichmentValidator
 * is what decides, per field, and an admin-locked field is skipped entirely. This class's only
 * jobs are to ask, to parse, and to throw rather than half-answer.
 *
 * ON "BELIEVABLE REALISM". The product requirement is that a player comparing two towns finds
 * the bigger one more expensive and the numbers survive being read out loud — not that a
 * valuation would satisfy a surveyor. The prompt says so explicitly, because a model told to be
 * precise about something it cannot be precise about will either refuse or invent false
 * confidence, and the honest framing produces better answers than either.
 */
final class ClaudeLocationEnricher implements LocationEnricherInterface
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** The long-stable Messages API version. Pinned, not latest, so a server-side change cannot alter parsing. */
    private const API_VERSION = '2023-06-01';

    /**
     * Bumped on ANY change to the prompt text or the schema below.
     *
     * Stored on every attempt row, so a batch of odd answers can be traced to the prompt that
     * produced them instead of being guessed at from timestamps.
     */
    private const PROMPT_VERSION = 'claude-v1';

    /** Small — the reply is five short fields. Generous enough that a thinking model has room. */
    private const MAX_TOKENS = 2_048;

    private const TIMEOUT_SECONDS = 45;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
        private readonly string              $apiKey,
        private readonly string              $model,
    ) {}

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    public function enrich(Location $location): EnrichmentResult
    {
        $this->assertConfigured();

        $payload = $this->request($this->prompt($location));

        // The structured-outputs contract puts the schema-valid JSON in the first text block as
        // a string. Parsed defensively anyway: a transport-level success with an unexpected
        // body shape must be an error we can see, not a silently empty result that looks like a
        // provider answering unhelpfully.
        $text = $payload['content'][0]['text'] ?? null;

        if (!is_string($text)) {
            throw new EnrichmentUnavailableException(sprintf(
                'Claude returned no text block for %s (stop_reason: %s).',
                $location->getTown(),
                (string) ($payload['stop_reason'] ?? 'unknown'),
            ));
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new EnrichmentUnavailableException(sprintf(
                'Claude returned text that is not JSON for %s.',
                $location->getTown(),
            ));
        }

        return new EnrichmentResult(
            lat: $this->floatOrNull($decoded['lat'] ?? null),
            lng: $this->floatOrNull($decoded['lng'] ?? null),
            population: $this->intOrNull($decoded['population'] ?? null),
            // Asked for in POUNDS and converted here. A model asked for a figure in pence
            // reliably loses track of the factor of 100 across a wide range, and an answer that
            // is wrong by 100x is inside the validator's plausible band for a different town —
            // so it would be accepted rather than caught. Pounds then x100 is the safe shape.
            landPence: $this->poundsToPence($decoded['land_value_gbp'] ?? null),
            region: $this->stringOrNull($decoded['region'] ?? null),
            raw: $decoded,
            inputTokens: $this->intOrNull($payload['usage']['input_tokens'] ?? null),
            outputTokens: $this->intOrNull($payload['usage']['output_tokens'] ?? null),
        );
    }

    /**
     * Cheapest possible round trip that proves the key, the model name and connectivity.
     *
     * One token of output against the configured model. It costs effectively nothing and is the
     * thing an operator should click BEFORE starting a sweep — an unconfigured or mistyped
     * setup discovered after 865 rows have each recorded a failure is the exact incident this
     * codebase already had once with Firebase.
     */
    public function checkConnection(): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'detail' => 'LOCATION_ENRICHER_API_KEY is empty.'];
        }

        if ($this->model === '') {
            return ['ok' => false, 'detail' => 'LOCATION_ENRICHER_MODEL is empty.'];
        }

        try {
            $this->request('Reply with the single word: ok', maxTokens: 16, withSchema: false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }

        return ['ok' => true, 'detail' => sprintf('Reached %s as model %s.', $this->provider(), $this->model)];
    }

    /**
     * One Messages API call.
     *
     * @return array<string, mixed>
     *
     * @throws EnrichmentUnavailableException on any transport, auth, rate-limit or server error
     */
    private function request(string $prompt, int $maxTokens = self::MAX_TOKENS, bool $withSchema = true): array
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];

        if ($withSchema) {
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => self::schema()]];
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'content-type'      => 'application/json',
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                ],
                'json'    => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            $raw    = $response->getContent(throw: false);
        } catch (HttpExceptionInterface $e) {
            // A transport failure is "could not ask", which the attempt row records as
            // outcome=error — deliberately distinct from "asked, got nonsense".
            throw new EnrichmentUnavailableException(
                sprintf('Could not reach the Claude API: %s', $e->getMessage()),
                previous: $e,
            );
        }

        if ($status !== 200) {
            $this->logger->error('Claude enrichment call failed', [
                'status' => $status,
                // Truncated: an error body can be large and this lands in a varchar(255) column
                // downstream. The status code is the part that identifies the problem.
                'body'   => substr($raw, 0, 500),
            ]);

            throw new EnrichmentUnavailableException(
                sprintf('Claude API returned HTTP %d: %s', $status, substr($raw, 0, 200)),
            );
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new EnrichmentUnavailableException('Claude API returned a body that is not JSON.');
        }

        return $decoded;
    }

    /**
     * The JSON schema the response is held to.
     *
     * EVERY FIELD IS REQUIRED AND NULLABLE, which is the combination that matters. Required
     * means the model cannot silently drop `lng` — the omission that produces a half-enriched
     * row nobody notices. Nullable means it can still decline a field honestly, which is a far
     * better answer than a confident guess, and the validator treats a null as "not supplied"
     * rather than as a rejection.
     *
     * `additionalProperties: false` so a model cannot smuggle an unasked-for field into `raw`
     * and have it look like data.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'lat'            => ['type' => ['number', 'null'], 'description' => 'Decimal degrees, WGS84.'],
                'lng'            => ['type' => ['number', 'null'], 'description' => 'Decimal degrees, WGS84.'],
                'population'     => ['type' => ['integer', 'null'], 'description' => 'People in the town or its urban area.'],
                'land_value_gbp' => [
                    'type'        => ['number', 'null'],
                    'description' => 'Plausible cost in POUNDS STERLING of a football-stadium-sized '
                        . 'plot of developable land in this town. Not per acre, not per square metre '
                        . '— the whole site.',
                ],
                'region'         => ['type' => ['string', 'null'], 'description' => 'Administrative region, county or state.'],
            ],
            'required'             => ['lat', 'lng', 'population', 'land_value_gbp', 'region'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The prompt.
     *
     * Gives the model what we already know — the town, the country, and any seeded region or
     * population — because the seeded figures are the anchor the validator checks a divergent
     * answer against, and a model that can see them produces answers consistent with them
     * instead of a second, unrelated estimate.
     *
     * States the realism bar explicitly. A model told to be precise about a land valuation it
     * cannot be precise about will either refuse or manufacture false confidence; told that
     * "believable and internally consistent" is the actual requirement, it gives a usable
     * figure and does not hedge.
     */
    private function prompt(Location $location): string
    {
        $country = $location->getCountry();

        $known = [sprintf('Town: %s', $location->getTown()), sprintf('Country: %s', $country->getName())];

        if ($location->getRegion() !== '') {
            $known[] = sprintf('Region (already on record): %s', $location->getRegion());
        }

        if ($location->getPopulation() !== null) {
            $known[] = sprintf('Population (already on record): %d', $location->getPopulation());
        }

        if ($location->isCapital()) {
            $known[] = 'This is the capital city.';
        }

        return <<<PROMPT
            You are supplying reference geography for a football management game.

            {$this->bulleted($known)}

            Return, for this specific town:

            1. lat / lng — the town centre, decimal degrees, WGS84. These MUST be inside
               {$country->getName()}. If you are not confident you have the right town, return null
               for both rather than a coordinate for a similarly-named place elsewhere.
            2. population — the town or its urban area, not the wider metropolitan region.
            3. land_value_gbp — in POUNDS STERLING, what a football-stadium-sized plot of
               developable land in this town would plausibly cost to buy. The whole site, not a
               rate per acre. Reflect local land values: the same plot should cost far more in an
               expensive city than in a cheap small town.
            4. region — the administrative region, county or state the town sits in.

            ACCURACY BAR: believable realism, not precision. A player comparing two towns must
            find the bigger or more expensive one more expensive, and every number must survive
            being read out loud. A plausible, internally consistent estimate is exactly what is
            wanted — do not hedge, and do not inflate confidence either.

            Return null for any single field you genuinely cannot estimate. A null is useful; a
            guess you would not stand behind is not.
            PROMPT;
    }

    /** @param list<string> $lines */
    private function bulleted(array $lines): string
    {
        return implode("\n", array_map(static fn (string $l): string => '- ' . $l, $lines));
    }

    private function assertConfigured(): void
    {
        if ($this->apiKey === '' || $this->model === '') {
            throw new EnrichmentUnavailableException(
                'The Claude enricher is selected but not configured. Set LOCATION_ENRICHER_API_KEY '
                . 'and LOCATION_ENRICHER_MODEL.',
            );
        }
    }

    private function floatOrNull(mixed $v): ?float
    {
        return is_int($v) || is_float($v) ? (float) $v : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_int($v) || (is_float($v) && $v == (int) $v) ? (int) $v : null;
    }

    /** Pounds to pence, rounded. See the note at the call site on why the API is asked in pounds. */
    private function poundsToPence(mixed $v): ?int
    {
        if (!is_int($v) && !is_float($v)) {
            return null;
        }

        return (int) round(((float) $v) * 100);
    }

    private function stringOrNull(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }

        $trimmed = trim($v);

        return $trimmed === '' ? null : $trimmed;
    }
}
