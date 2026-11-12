<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

use App\Entity\Location\CountryProfile;
use App\Service\Ai\AiReplyTruncatedException;
use App\Service\Ai\AiUnavailableException;
use App\Service\Ai\AnthropicJsonClient;
use App\Service\CountryContent\NamePoolValidator;

/**
 * Asks the Claude Messages API for more first names or surnames for one country.
 *
 * Prompt, schema and parsing only — the HTTP is AnthropicJsonClient's, shared with the three
 * other AI features here.
 *
 * ONE LIST PER CALL, AND ONLY THE SHORTFALL. A country holding thirty surnames and no first
 * names asks for a hundred and twenty of one and a hundred and fifty of the other, in two
 * calls; a country at target asks for nothing and costs nothing. The names already held go in
 * the prompt as an exclusion list, for the reason TownSuggestionService records: without it
 * the model returns what you already have and the run pays for nothing.
 *
 * THE HARD PART IS CULTURAL, NOT STRUCTURAL. A model asked for "surnames for Albania" will
 * return plausible-looking output whatever it knows; what makes the answer useful is insisting
 * on local orthography and on names a football crowd would actually recognise. So the prompt
 * names the sport, asks for the spread a squad list would have, and forbids the anglicised
 * fallback explicitly.
 *
 * EFFORT IS LOW. This is recall against a fixed schema, and thinking tokens come out of the
 * same max_tokens as the answer — the mechanism that truncated a third of the town batches
 * before they were sized down.
 */
final class ClaudeNamePoolSuggester implements NamePoolSuggesterInterface
{
    /** Bumped on ANY change to the prompt text or the schema below. */
    private const PROMPT_VERSION = 'name-pool-claude-v1';

    /**
     * Roughly four tokens a name plus JSON overhead, with room for the model to think.
     *
     * A hundred and fifty short strings is about 750 output tokens. The headroom above that is
     * the lesson from ClaudeTownSuggester, whose batches truncated at a ceiling that looked
     * generous until adaptive thinking was billed against it.
     */
    private const MAX_TOKENS = 6_144;

    private const TIMEOUT_SECONDS = 90;

    /** Sent so the model knows what it is competing with, not to be copied. */
    private const MAX_EXCLUSIONS_IN_PROMPT = 400;

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

    public function suggest(CountryProfile $country, string $list, array $exclude, int $want): NamePoolSuggestion
    {
        if (!$this->client->isConfigured()) {
            throw new NamePoolUnavailableException(UnconfiguredNamePoolSuggester::MESSAGE);
        }

        $want = max(1, min($want, NamePoolValidator::MAX_PER_LIST));

        try {
            $response = $this->client->sendJson(
                $this->prompt($country, $list, $exclude, $want),
                self::schema($list),
                self::MAX_TOKENS,
                sprintf('%s for %s', $this->label($list), $country->getName()),
                self::TIMEOUT_SECONDS,
                AnthropicJsonClient::EFFORT_LOW,
            );
        } catch (AiReplyTruncatedException $e) {
            // Kept distinct: an identical retry cannot help, where a transient fault would.
            throw new NamePoolUnavailableException($e->getMessage(), previous: $e);
        } catch (AiUnavailableException $e) {
            throw new NamePoolUnavailableException($e->getMessage(), previous: $e);
        }

        return new NamePoolSuggestion(
            names: $this->strings($response->data['names'] ?? null),
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
        );
    }

    /**
     * NO minItems/maxItems, matching the three sibling schemas here: the validator is the
     * enforcement point, and a constraint the model cannot satisfy turns a partly good answer
     * into a total loss.
     *
     * @return array<string, mixed>
     */
    private static function schema(string $list): array
    {
        $what = $list === 'firstNames'
            ? 'Given names, as locally written.'
            : 'Family names, as locally written.';

        return [
            'type'       => 'object',
            'properties' => [
                'names' => [
                    'type'        => 'array',
                    'description' => $what . ' One name per entry, no titles, no initials.',
                    'items'       => ['type' => 'string'],
                ],
            ],
            'required'             => ['names'],
            'additionalProperties' => false,
        ];
    }

    /** @param list<string> $exclude */
    private function prompt(CountryProfile $country, string $list, array $exclude, int $want): string
    {
        $name  = $country->getName();
        $label = $this->label($list);

        $kept     = \array_slice($exclude, 0, self::MAX_EXCLUSIONS_IN_PROMPT);
        $heldLine = $kept === []
            ? "We hold none for {$name} yet, so this is the whole list."
            : sprintf(
                "We already hold these — do NOT return any of them:\n%s",
                implode(', ', $kept),
            );

        $truncated = \count($exclude) > \count($kept)
            ? sprintf("\n(That list is truncated; we hold %d in total.)", \count($exclude))
            : '';

        $kind = $list === 'firstNames'
            ? 'GIVEN names — the name a player is called by, not a family name.'
            : 'FAMILY names — surnames, not given names.';

        return <<<PROMPT
            You are supplying a pool of real {$label} for a football management game, to name
            players and staff generated for {$name}.

            {$heldLine}{$truncated}

            Return up to {$want} further {$label} used in {$name}.

            {$kind}

            THE NAMES MUST BE LOCAL AND REAL. Give the names an ordinary squad list from
            {$name} would contain, in the local language and script, with diacritics intact.
            Do NOT anglicise them, do NOT transliterate away accents, and do NOT fall back on
            internationally familiar names unless they are genuinely common there. The generic
            answer is the one the game already has, and it is what this request exists to
            replace.

            SPREAD THEM. A squad list is not twenty variations on the same root: include the
            common names and the ordinary ones, across the regions and communities that make
            up the country, in roughly the proportion a real league would show.

            Do not repeat anything in the list above, and do not repeat yourself. One name per
            entry — no titles, no initials, no nicknames in brackets.

            If {$name} does not have {$want} further {$label} worth listing, return fewer. A
            short, correct list is the right answer; padding it is not.
            PROMPT;
    }

    private function label(string $list): string
    {
        return $list === 'firstNames' ? 'first names' : 'surnames';
    }

    /**
     * @param  mixed        $raw
     * @return list<string>
     */
    private function strings(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $value) {
            if (\is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }

        return $out;
    }
}
