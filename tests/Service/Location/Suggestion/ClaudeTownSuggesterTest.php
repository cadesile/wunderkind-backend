<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\Suggestion;

use App\Entity\Location\CountryProfile;
use App\Service\Ai\AnthropicJsonClient;
use App\Service\Location\Suggestion\ClaudeTownSuggester;
use App\Service\Location\Suggestion\TownSuggestionUnavailableException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What we ask for, and what we do with the answer.
 *
 * MockHttpClient throughout — the suite must never make a real, billed API call.
 */
class ClaudeTownSuggesterTest extends TestCase
{
    public function testItParsesTheTownsOutOfTheReply(): void
    {
        $suggester = $this->suggester(fn () => $this->reply([
            ['name' => 'Rosario', 'population' => 1_193_000, 'region' => 'Santa Fe'],
            ['name' => 'Mendoza', 'population' => 115_000, 'region' => 'Mendoza'],
        ]));

        $result = $suggester->suggest($this->argentina(), [], 10);

        $this->assertCount(2, $result->towns);
        $this->assertSame('Rosario', $result->towns[0]->name);
        $this->assertSame(1_193_000, $result->towns[0]->population);
        $this->assertSame('Santa Fe', $result->towns[0]->region);
    }

    public function testTheExclusionListGoesIntoThePrompt(): void
    {
        $prompt = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$prompt) {
            $prompt = json_decode($options['body'], true)['messages'][0]['content'];

            return $this->reply([]);
        });

        $suggester->suggest($this->argentina(), ['Buenos Aires', 'Córdoba'], 10);

        $this->assertStringContainsString('Buenos Aires', $prompt);
        $this->assertStringContainsString('Córdoba', $prompt);
        $this->assertStringContainsString('Argentina', $prompt);
    }

    /**
     * A country we hold nothing for is asked for the BIGGEST towns, not for further ones.
     *
     * The prompt used to say "Return up to N FURTHER towns and cities" whatever the state,
     * with a separate line admitting the catalogue was empty. For a fresh country that asks
     * the model to go beyond a list that does not exist, which pushes it past the obvious
     * places — and the obvious places are exactly what a new country needs first, because
     * city_size is a percentile across the whole set and everything downstream is ranked off
     * population.
     */
    public function testAFreshCountryIsAskedToStartAtTheTop(): void
    {
        $prompt = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$prompt) {
            $prompt = json_decode($options['body'], true)['messages'][0]['content'];

            return $this->reply([]);
        });

        $suggester->suggest($this->argentina(), [], 10);

        $this->assertStringContainsString('hold NO towns', $prompt);
        $this->assertStringContainsString('beginning with the largest', $prompt);
        $this->assertStringContainsString('capital', $prompt);
        $this->assertStringNotContainsString('FURTHER', $prompt, 'there is nothing to go further than');
    }

    public function testACountryWeAlreadyHoldTownsForIsAskedForFurtherOnes(): void
    {
        $prompt = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$prompt) {
            $prompt = json_decode($options['body'], true)['messages'][0]['content'];

            return $this->reply([]);
        });

        $suggester->suggest($this->argentina(), ['Buenos Aires'], 10);

        $this->assertStringContainsString('FURTHER', $prompt);
        $this->assertStringContainsString('do NOT return any of them', $prompt);
    }

    public function testAVeryLongExclusionListIsTruncatedAndSaysSo(): void
    {
        // England holds thousands. Sending them all would cost more in input tokens than the
        // answer is worth and would crowd out the instruction — the server-side dedupe is what
        // guarantees correctness, so the prompt list only has to be good enough to stop the
        // model wasting its reply on obvious repeats.
        $prompt = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$prompt) {
            $prompt = json_decode($options['body'], true)['messages'][0]['content'];

            return $this->reply([]);
        });

        $existing = array_map(static fn (int $i): string => 'Town' . $i, range(1, 3_000));
        $suggester->suggest($this->argentina(), $existing, 10);

        $this->assertStringContainsString('Town1,', $prompt);
        $this->assertStringNotContainsString('Town2999', $prompt);
        $this->assertStringContainsString('we hold 3000 in total', $prompt);
    }

    public function testItAsksForStructuredOutputWithAnArraySchema(): void
    {
        // A list, not the five-scalar object the enricher asks for. `name` is required and not
        // nullable: a town with no name is noise, not a partial answer.
        $body = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$body) {
            $body = json_decode($options['body'], true);

            return $this->reply([]);
        });

        $suggester->suggest($this->argentina(), [], 10);

        $schema = $body['output_config']['format']['schema'];
        $this->assertSame('array', $schema['properties']['towns']['type']);
        $this->assertSame('string', $schema['properties']['towns']['items']['properties']['name']['type']);
        $this->assertFalse($schema['properties']['towns']['items']['additionalProperties']);
    }

    public function testItAsksForLowEffortBecauseNamingTownsIsRecallNotReasoning(): void
    {
        // Thinking tokens come out of the same max_tokens as the answer, and the API's default
        // effort of high was spending enough of a 6,144-token ceiling to truncate roughly a
        // third of a US run's batches while the rest cleared the identical ask.
        $body = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$body) {
            $body = json_decode($options['body'], true);

            return $this->reply([]);
        });

        $suggester->suggest($this->argentina(), [], 10);

        $this->assertSame('low', $body['output_config']['effort']);
    }

    public function testTheTokenBudgetScalesWithTheNumberAskedFor(): void
    {
        // A reply truncated mid-array decodes as invalid JSON and loses the whole call — so the
        // budget has to follow the cap rather than sit at the enricher's five-field default.
        $small = $this->capturedMaxTokens(10);
        $large = $this->capturedMaxTokens(300);

        $this->assertGreaterThan($small, $large);
        $this->assertGreaterThanOrEqual(300 * 40, $large);
    }

    public function testRowsWithoutAUsableNameAreDroppedRatherThanBreakingTheBatch(): void
    {
        $suggester = $this->suggester(fn () => $this->reply([
            ['name' => 'Salta', 'population' => 520_000, 'region' => 'Salta'],
            ['population' => 1, 'region' => 'nowhere'],
            'not even an object',
        ]));

        $result = $suggester->suggest($this->argentina(), [], 10);

        $this->assertCount(1, $result->towns);
        $this->assertSame('Salta', $result->towns[0]->name);
    }

    public function testAskingForNoneMakesNoCallAtAll(): void
    {
        $called = false;
        $suggester = $this->suggester(function () use (&$called) {
            $called = true;

            return $this->reply([]);
        });

        $result = $suggester->suggest($this->argentina(), [], 0);

        $this->assertFalse($called, 'A cap of zero must not spend anything.');
        $this->assertTrue($result->isEmpty());
    }

    public function testAnUnconfiguredClientRefusesLoudly(): void
    {
        $suggester = new ClaudeTownSuggester(
            new AnthropicJsonClient(new MockHttpClient(), new NullLogger(), '', ''),
        );

        $this->expectException(TownSuggestionUnavailableException::class);

        $suggester->suggest($this->argentina(), [], 10);
    }

    public function testATransportFailureSurfacesAsASuggestionFailure(): void
    {
        // Rethrown as this feature's own exception so callers do not have to know the HTTP
        // layer exists.
        $suggester = $this->suggester(fn () => new MockResponse('nope', ['http_code' => 500]));

        $this->expectException(TownSuggestionUnavailableException::class);

        $suggester->suggest($this->argentina(), [], 10);
    }

    /**
     * Regression: the budget had no room for the model's own reasoning.
     *
     * Thinking tokens count against max_tokens, and on adaptive-thinking models the model
     * decides per request whether to think — a fifty-town list qualifies. At the old 2,048
     * floor the reasoning ate the budget and the list was truncated, which is what the first
     * real US run hit: stop_reason max_tokens on a batch of fifty.
     *
     * Asserting a floor rather than an exact figure: the point is headroom, and pinning the
     * arithmetic would make a later increase look like a break.
     */
    public function testTheBudgetLeavesRoomForTheModelToThink(): void
    {
        $batch = $this->capturedMaxTokens(50);

        $this->assertGreaterThan(
            4_096,
            $batch,
            'a 50-town batch needs room for the list AND the reasoning that precedes it',
        );
    }

    public function testEvenASingleTownGetsThinkingHeadroom(): void
    {
        // The floor applies to the smallest ask too — a one-town batch still reasons, and a
        // budget that only covered the answer would truncate before reaching it.
        $this->assertGreaterThan(4_096, $this->capturedMaxTokens(1));
    }

    private function capturedMaxTokens(int $max): int
    {
        $seen = 0;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$seen) {
            $seen = json_decode($options['body'], true)['max_tokens'];

            return $this->reply([]);
        });

        $suggester->suggest($this->argentina(), [], $max);

        return $seen;
    }

    private function suggester(callable $handler): ClaudeTownSuggester
    {
        return new ClaudeTownSuggester(
            new AnthropicJsonClient(new MockHttpClient($handler), new NullLogger(), 'sk-ant-test', 'claude-test'),
        );
    }

    /** @param list<mixed> $towns */
    private function reply(array $towns): MockResponse
    {
        return new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => json_encode(['towns' => $towns])]],
            'usage'   => ['input_tokens' => 10, 'output_tokens' => 20],
        ]), ['http_code' => 200]);
    }

    private function argentina(): CountryProfile
    {
        return new CountryProfile('AR', 'Argentina');
    }
}
