<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Location\CountryProfile;
use App\Service\Location\CountryEnrichment\CountryEnrichmentUnavailableException;
use App\Service\Location\CountryEnrichment\CountryProfileEnricherInterface;
use App\Service\Location\CountryEnrichment\CountryProfileFacts;

/**
 * A CountryProfileEnricherInterface that answers from a script instead of calling a provider.
 *
 * Same contract and the same reason as FakeLocationEnricher: the suite must never make a real
 * call, which would be slow, non-deterministic and billed. Tests queue exactly the answers the
 * case is about — including the ones a validator has to refuse.
 */
final class FakeCountryProfileEnricher implements CountryProfileEnricherInterface
{
    /** @var list<CountryProfileFacts|\Throwable> */
    private array $script = [];

    private int $calls = 0;

    /** @var list<string> country codes, in call order */
    private array $seen = [];

    private bool $configured = true;

    public function queue(CountryProfileFacts|\Throwable $answer): self
    {
        $this->script[] = $answer;

        return $this;
    }

    /** Queue the same answer for every call, however many come. */
    public function always(CountryProfileFacts|\Throwable $answer): self
    {
        $this->script = [$answer];

        return $this;
    }

    public function setConfigured(bool $configured): self
    {
        $this->configured = $configured;

        return $this;
    }

    public function enrich(CountryProfile $country): CountryProfileFacts
    {
        $this->calls++;
        $this->seen[] = $country->getCode();

        if (!$this->configured) {
            throw new CountryEnrichmentUnavailableException('fake enricher is not configured');
        }

        // The last queued answer repeats, so always() works and a test that queues one answer
        // for a loop does not fall off the end.
        $answer = $this->script === []
            ? new CountryProfileFacts()
            : ($this->script[$this->calls - 1] ?? $this->script[array_key_last($this->script)]);

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
        return 'fake-model';
    }

    public function promptVersion(): string
    {
        return 'fake-v1';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    /** @return list<string> */
    public function seenCountries(): array
    {
        return $this->seen;
    }
}
