<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

use App\Entity\Location\CountryProfile;
use App\Service\Ai\AnthropicJsonClient;

/**
 * Asks Claude for towns a country's catalogue is missing.
 *
 * THE MODEL IS NOT ASKED FOR GEOGRAPHY, only for names. Everything else — coordinates,
 * population, region, land value — is the existing enrichment's job, and it is better at it:
 * it asks one focused question per town against a bounding box it can be checked with,
 * rather than four hundred answers in one reply that nothing can verify.
 *
 * POPULATION IS REQUESTED AND THEN THROWN AWAY. It is the sort key. Asked for it, the model
 * returns the towns that matter first, and the server can enforce that ordering instead of
 * trusting the reply to be sorted — which is what makes a cap mean "the 300 biggest" rather
 * than "the first 300 it happened to say". Persisting it is explicitly wrong; see SuggestedTown.
 *
 * HALLUCINATION IS HANDLED DOWNSTREAM, NOT HERE. An invented town gets coordinates that fail
 * EnrichmentValidator's bounding-box check, which marks the row needs_review, which
 * CountryReadinessService treats as blocking. A bad name surfaces on the operator's review
 * queue rather than quietly shipping — so this class does not try to second-guess the model,
 * which it could only do with a heuristic that would also reject real towns.
 */
final class ClaudeTownSuggester implements TownSuggesterInterface
{
    /** Bumped on ANY change to the prompt text or the schema below. */
    private const PROMPT_VERSION = 'towns-claude-v1';

    /**
     * Roughly 25 tokens per town plus JSON overhead, with headroom.
     *
     * Far above the enricher's 2,048 because this reply is a list, not five scalars: a cap of
     * 300 towns truncated mid-array would decode as invalid JSON and lose the whole call.
     */
    private const TOKENS_PER_TOWN = 40;

    private const MIN_TOKENS = 2_048;

    private const MAX_TOKENS = 16_384;

    /**
     * Longer than the enricher's: composing hundreds of names takes the model longer.
     *
     * This is the ceiling for a BIG single call. The queued admin path asks in batches of
     * fifty and passes a much shorter timeout, because at 120s one stalled batch would burn
     * a large share of its consumer's time budget before the first retry.
     */
    private const TIMEOUT_SECONDS = 120;

    /**
     * How many existing names to put in the prompt.
     *
     * Sending them all would cost more in input tokens than the answer is worth, and would
     * crowd out the instruction. The server-side dedupe against the full set is what guarantees
     * correctness, so this only has to be good enough to stop the model wasting its answer on
     * obvious repeats.
     *
     * RAISED FROM 400, which was sized against a claim that was not true — the comment here
     * used to say "England already holds thousands", where in fact the largest country in the
     * registry has ~330. So 400 truncated nothing at all until a country had been through a
     * full 300-town pull, and then it started silently truncating the very names that pull had
     * just added, because imported towns have no population and sort into the tail of the
     * ordering (see TownSuggestionService::existingTownNames()).
     *
     * That mattered little for one big call, which paid the penalty once. It matters a great
     * deal for a batched run, where every batch asks the same question and the exclusion list
     * is the only thing that changes the answer. 1,500 names is roughly 5-6k input tokens —
     * cents — and keeps truncation away for several more full runs.
     */
    public const MAX_EXCLUSIONS_IN_PROMPT = 1_500;

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

    public function suggest(CountryProfile $country, array $excludeNames, int $max, ?int $timeoutSeconds = null): TownSuggestionResult
    {
        if (!$this->client->isConfigured()) {
            throw new TownSuggestionUnavailableException(
                'The Claude town suggester is selected but not configured. Set '
                . 'LOCATION_ENRICHER_API_KEY and LOCATION_ENRICHER_MODEL.',
            );
        }

        if ($max < 1) {
            return new TownSuggestionResult([]);
        }

        try {
            $response = $this->client->sendJson(
                $this->prompt($country, $excludeNames, $max),
                self::schema(),
                $this->tokenBudget($max),
                \sprintf('town list for %s', $country->getName()),
                $timeoutSeconds ?? self::TIMEOUT_SECONDS,
            );
        } catch (\App\Service\Ai\AiUnavailableException $e) {
            throw new TownSuggestionUnavailableException($e->getMessage(), previous: $e);
        }

        $towns = [];

        foreach ($response->data['towns'] ?? [] as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $name = $row['name'] ?? null;

            if (!\is_string($name)) {
                continue;
            }

            $towns[] = new SuggestedTown(
                name: trim($name),
                population: self::intOrNull($row['population'] ?? null),
                region: self::stringOrNull($row['region'] ?? null),
            );
        }

        return new TownSuggestionResult($towns, $response->inputTokens, $response->outputTokens);
    }

    public function checkConnection(): array
    {
        if (!$this->client->hasApiKey()) {
            return ['ok' => false, 'detail' => 'LOCATION_ENRICHER_API_KEY is empty.'];
        }

        if ($this->client->model() === '') {
            return ['ok' => false, 'detail' => 'LOCATION_ENRICHER_MODEL is empty.'];
        }

        try {
            $this->client->send('Reply with the single word: ok', maxTokens: 16);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }

        return ['ok' => true, 'detail' => \sprintf('Reached %s as model %s.', $this->provider(), $this->client->model())];
    }

    private function tokenBudget(int $max): int
    {
        return min(self::MAX_TOKENS, max(self::MIN_TOKENS, $max * self::TOKENS_PER_TOWN));
    }

    /**
     * The JSON schema the reply is held to.
     *
     * An array response, which the enricher's single-object schema does not cover. `name` is
     * required and NOT nullable — a town with no name is not a partial answer, it is noise.
     * The other two are nullable so the model can decline rather than invent, and a town whose
     * population it will not guess simply sorts last.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'towns' => [
                    'type'        => 'array',
                    'description' => 'Towns and cities, largest population first.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'name'       => ['type' => 'string', 'description' => 'The town name as locally written, no country or region suffix.'],
                            'population' => ['type' => ['integer', 'null'], 'description' => 'People in the town or its urban area. Used only for ordering.'],
                            'region'     => ['type' => ['string', 'null'], 'description' => 'Administrative region, county or state.'],
                        ],
                        'required'             => ['name', 'population', 'region'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['towns'],
            'additionalProperties' => false,
        ];
    }

    /** @param list<string> $excludeNames */
    private function prompt(CountryProfile $country, array $excludeNames, int $max): string
    {
        $name     = $country->getName();
        $excluded = \array_slice($excludeNames, 0, self::MAX_EXCLUSIONS_IN_PROMPT);

        $alreadyHeld = $excluded === []
            ? 'We currently hold no towns for this country.'
            : \sprintf(
                "We already hold these towns — do NOT return any of them:\n%s",
                implode(', ', $excluded),
            );

        $andMore = \count($excludeNames) > \count($excluded)
            ? \sprintf("\n(That list is truncated; we hold %d in total.)", \count($excludeNames))
            : '';

        return <<<PROMPT
            You are extending the town catalogue for a football management game set in {$name}.

            {$alreadyHeld}{$andMore}

            Return up to {$max} further REAL towns and cities in {$name}, ordered by population,
            largest first.

            Rules:
            - Real settlements only. A town that does not exist is worse than a short list — it
              will be caught later and thrown away, having cost us the lookup.
            - The settlement's own name, as locally written. No country, region or administrative
              suffix appended, and no disambiguation in brackets.
            - Somewhere a football club could plausibly be based. Towns, cities and large
              suburbs; not hamlets, villages of a few hundred, or neighbourhoods of a city you
              have already listed separately.
            - Do not repeat anything in the list above, and do not repeat yourself.
            - Spread them across the country rather than returning one region's worth.

            If {$name} does not have {$max} further towns worth listing, return fewer. A short,
            correct list is the right answer; padding it is not.
            PROMPT;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return \is_int($v) || (\is_float($v) && $v == (int) $v) ? (int) $v : null;
    }

    private static function stringOrNull(mixed $v): ?string
    {
        if (!\is_string($v)) {
            return null;
        }

        $trimmed = trim($v);

        return $trimmed === '' ? null : $trimmed;
    }
}
