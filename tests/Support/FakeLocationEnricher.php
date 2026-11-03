<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Location\Location;
use App\Service\Location\Enrichment\EnrichmentResult;
use App\Service\Location\Enrichment\EnrichmentUnavailableException;
use App\Service\Location\Enrichment\LocationEnricherInterface;

/**
 * A LocationEnricherInterface that answers from a script instead of calling a provider.
 *
 * The suite must never make a real enrichment call: it would be slow, non-deterministic, and
 * billed. Tests bind this into the container in place of the configured enricher and queue up
 * exactly the answers — including the absurd ones — that the case is about.
 */
final class FakeLocationEnricher implements LocationEnricherInterface
{
    /** @var list<EnrichmentResult|\Throwable> */
    private array $script = [];

    private int $calls = 0;

    /** @var list<string> town names, in call order */
    private array $seen = [];

    private bool $connected = true;

    public function queue(EnrichmentResult|\Throwable $answer): self
    {
        $this->script[] = $answer;

        return $this;
    }

    /** Queue the same answer for every call, however many come. */
    public function always(EnrichmentResult|\Throwable $answer): self
    {
        $this->script = [$answer];

        return $this;
    }

    public function setConnected(bool $connected): self
    {
        $this->connected = $connected;

        return $this;
    }

    public function enrich(Location $location): EnrichmentResult
    {
        $this->seen[] = $location->getTown();

        $answer = $this->script[$this->calls] ?? ($this->script[count($this->script) - 1] ?? null);
        ++$this->calls;

        if ($answer === null) {
            throw new EnrichmentUnavailableException('FakeLocationEnricher had no scripted answer.');
        }

        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-1';
    }

    public function promptVersion(): string
    {
        return 'test';
    }

    public function checkConnection(): array
    {
        return $this->connected
            ? ['ok' => true, 'detail' => 'fake enricher']
            : ['ok' => false, 'detail' => 'fake enricher is offline'];
    }

    public function calls(): int
    {
        return $this->calls;
    }

    /** @return list<string> */
    public function seenTowns(): array
    {
        return $this->seen;
    }

    public function reset(): void
    {
        $this->script = [];
        $this->calls  = 0;
        $this->seen   = [];
    }
}
