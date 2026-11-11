<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

use App\Entity\Location\CountryProfile;
use App\Enum\Country;
use App\Service\Ai\AiUnavailableException;
use App\Service\Ai\AnthropicJsonClient;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\CountryContent\NamingContentValidator;

/**
 * Asks the Claude Messages API for a country's club-name suffixes and stadium templates.
 *
 * Prompt, schema and parsing only — the HTTP is AnthropicJsonClient's, shared with location
 * enrichment and town suggestion.
 *
 * THE HARD PART IS CULTURAL, NOT STRUCTURAL. A model asked for "club name suffixes for Poland"
 * will happily return United, City and FC, which are the one answer that makes a generated
 * Polish league read like a translation. So the prompt names the mechanic, insists on local
 * orthography, forbids English defaults where a local word exists, and shows Spain's authored
 * lists as a SHAPE example with an explicit instruction not to reuse their words.
 *
 * THE %s RULE IS STATED TWICE — in the schema description and again in the prompt — because it
 * is the one mistake with a runtime cost. A stadium template is handed to sprintf(), which
 * throws in PHP 8 on a malformed format. NamingContentValidator refuses a bad one, but a
 * suggester that routinely produced them would make the button useless.
 *
 * EFFORT IS LOW: recall against a fixed schema, not reasoning, and thinking tokens come out of
 * the same max_tokens as the answer.
 */
final class ClaudeNamingContentSuggester implements NamingContentSuggesterInterface
{
    /** Bumped on ANY change to the prompt text or the schema below. */
    private const PROMPT_VERSION = 'naming-content-claude-v1';

    /** Roughly 45 short tokens is a few hundred output tokens; the rest is thinking headroom. */
    private const MAX_TOKENS = 4_096;

    private const TIMEOUT_SECONDS = 60;

    /**
     * The country whose authored lists are shown as a shape example.
     *
     * Spain, because its three lists are short, unmistakably local, and in a language far
     * enough from English that copying them would be obvious. If echoed tokens ever show up in
     * real output, delete the example block and bump PROMPT_VERSION — that is the first thing
     * to try, before rewriting anything else.
     */
    private const EXAMPLE_COUNTRY = Country::ES;

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

    public function suggest(CountryProfile $country): NamingContentSuggestion
    {
        if (!$this->client->isConfigured()) {
            throw new NamingContentUnavailableException(UnconfiguredNamingContentSuggester::MESSAGE);
        }

        try {
            $response = $this->client->sendJson(
                $this->prompt($country),
                self::schema(),
                self::MAX_TOKENS,
                sprintf('club-naming content for %s', $country->getName()),
                self::TIMEOUT_SECONDS,
                AnthropicJsonClient::EFFORT_LOW,
            );
        } catch (AiUnavailableException $e) {
            throw new NamingContentUnavailableException($e->getMessage(), previous: $e);
        }

        return new NamingContentSuggestion(
            prestigeSuffixes: $this->strings($response->data['prestigeSuffixes'] ?? null),
            genericSuffixes: $this->strings($response->data['genericSuffixes'] ?? null),
            stadiumFormats: $this->strings($response->data['stadiumFormats'] ?? null),
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
        );
    }

    /**
     * NO minItems/maxItems, deliberately.
     *
     * None of the existing schemas here use them, NamingContentValidator is the enforcement
     * point, and a schema constraint the model cannot satisfy turns a partly good answer into
     * a total loss rather than two good categories and one refusal.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'prestigeSuffixes' => [
                    'type'        => 'array',
                    'description' => 'Club-name words and abbreviations a big-city club carries, as locally written.',
                    'items'       => ['type' => 'string'],
                ],
                'genericSuffixes' => [
                    'type'        => 'array',
                    'description' => 'The same, for ordinary lower-division and small-town clubs.',
                    'items'       => ['type' => 'string'],
                ],
                'stadiumFormats' => [
                    'type'        => 'array',
                    'description' => 'sprintf templates. Each MUST contain the two characters %s exactly once, '
                        . 'and no other percent sign anywhere.',
                    'items'       => ['type' => 'string'],
                ],
            ],
            'required'             => ['prestigeSuffixes', 'genericSuffixes', 'stadiumFormats'],
            'additionalProperties' => false,
        ];
    }

    private function prompt(CountryProfile $country): string
    {
        $name     = $country->getName();
        $prestige = NamingContentValidator::SUGGESTED_PRESTIGE;
        $generic  = NamingContentValidator::SUGGESTED_GENERIC;
        $formats  = NamingContentValidator::SUGGESTED_FORMATS;
        $example  = $this->exampleBlock();

        return <<<PROMPT
            You are supplying club-naming content for a football management game, for {$name}.

            The game generates football clubs by joining a real town name to a short club-name
            word. So "Madrid" plus "CF" becomes Madrid CF, and "Warszawa" plus "Legia" becomes
            Legia Warszawa. The server already knows which way round {$name} writes it — do not
            try to decide that, and do not include any town or city name in your answer.

            Return three lists:

            1. prestigeSuffixes — about {$prestige} club-name words carried by the biggest,
               oldest, most prestigious clubs in {$name}.
            2. genericSuffixes — about {$generic} club-name words carried by ordinary
               lower-division and small-town clubs there.
            3. stadiumFormats — about {$formats} templates for what a ground is called.

            THE NAMES MUST BE LOCAL. Give the abbreviations and words a football supporter in
            {$name} would actually recognise, in the local language and script, with diacritics
            intact. Do NOT translate them to English, and do NOT fall back to United, City or FC
            where a local word exists. This is the whole point of the request: the generic
            answer is one the game already has.

            DO NOT INCLUDE:
            - town, city or region names
            - the full name of a real club
            - the same word in both suffix lists
            - duplicates within a list

            STADIUM FORMATS ARE TEMPLATES. Each one must contain the two characters %s exactly
            once, where the town name goes, and must contain NO other percent sign — no %d, no
            %1\$s, no %%, and no literal percent anywhere. A format of just "%s" is not useful:
            it must add words around it.

            {$example}

            A short, correct list is better than a padded one. If you are confident about only
            six generic suffixes, give six.
            PROMPT;
    }

    /** Spain's authored lists, as a shape example that explicitly must not be copied. */
    private function exampleBlock(): string
    {
        $country  = self::EXAMPLE_COUNTRY;
        $prestige = implode(', ', CountryContentRegistry::prestigeSuffixes($country));
        $generic  = implode(', ', \array_slice(CountryContentRegistry::genericSuffixes($country), 0, 10));
        $formats  = implode(', ', CountryContentRegistry::stadiumFormats($country));

        return <<<EXAMPLE
            FOR SHAPE ONLY — this is what Spain's three lists look like. Do not reuse any of
            these words; {$this->exampleName()} is not the country you are answering about.

            - prestigeSuffixes: {$prestige}
            - genericSuffixes: {$generic}
            - stadiumFormats: {$formats}
            EXAMPLE;
    }

    private function exampleName(): string
    {
        return self::EXAMPLE_COUNTRY->label();
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
