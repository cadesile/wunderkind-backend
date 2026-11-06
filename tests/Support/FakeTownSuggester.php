<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Location\CountryProfile;
use App\Service\Location\Suggestion\SuggestedTown;
use App\Service\Location\Suggestion\TownSuggesterInterface;
use App\Service\Location\Suggestion\TownSuggestionResult;

/**
 * A scripted suggester, so tests never make a real call.
 *
 * The suite must never call a provider for real: it would be slow, non-deterministic and
 * billed. Same reasoning and same shape as FakeLocationEnricher.
 */
final class FakeTownSuggester implements TownSuggesterInterface
{
    /** @var list<TownSuggestionResult|\Throwable> */
    private array $script = [];

    private ?TownSuggestionResult $always = null;

    private bool $connected = true;

    /** @var list<array{country: string, exclude: list<string>, max: int}> */
    private array $calls = [];

    /** @param list<string> $names */
    public function queueNames(array $names): self
    {
        // Descending populations, so a result used without an explicit order still exercises
        // the "biggest first" path rather than accidentally arriving pre-sorted by luck.
        $population = 1_000_000;
        $towns      = [];

        foreach ($names as $name) {
            $towns[]     = new SuggestedTown($name, $population);
            $population -= 1_000;
        }

        $this->script[] = new TownSuggestionResult($towns, inputTokens: 10, outputTokens: 20);

        return $this;
    }

    public function queue(TownSuggestionResult|\Throwable $next): self
    {
        $this->script[] = $next;

        return $this;
    }

    public function always(TownSuggestionResult $result): self
    {
        $this->always = $result;

        return $this;
    }

    public function setConnected(bool $connected): self
    {
        $this->connected = $connected;

        return $this;
    }

    public function suggest(CountryProfile $country, array $excludeNames, int $max): TownSuggestionResult
    {
        $this->calls[] = ['country' => $country->getCode(), 'exclude' => $excludeNames, 'max' => $max];

        $next = array_shift($this->script) ?? $this->always ?? new TownSuggestionResult([]);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-towns-1';
    }

    public function promptVersion(): string
    {
        return 'test';
    }

    public function checkConnection(): array
    {
        return ['ok' => $this->connected, 'detail' => $this->connected ? 'fake' : 'fake is offline'];
    }

    /** @return list<array{country: string, exclude: list<string>, max: int}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function reset(): void
    {
        $this->script = [];
        $this->always = null;
        $this->calls  = [];
    }
}
