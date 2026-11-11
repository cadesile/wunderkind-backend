<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Location\CountryProfile;
use App\Service\CountryContent\Suggestion\NamingContentSuggesterInterface;
use App\Service\CountryContent\Suggestion\NamingContentSuggestion;
use App\Service\CountryContent\Suggestion\NamingContentUnavailableException;

/**
 * A NamingContentSuggesterInterface that answers from a script.
 *
 * Same reason as the other two fakes in this directory: no test may make a real provider call
 * — it would be slow, non-deterministic and billed.
 */
final class FakeNamingContentSuggester implements NamingContentSuggesterInterface
{
    private ?NamingContentSuggestion $answer = null;

    private ?\Throwable $failure = null;

    private int $calls = 0;

    private bool $configured = true;

    public function answer(NamingContentSuggestion $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    public function fail(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function setConfigured(bool $configured): self
    {
        $this->configured = $configured;

        return $this;
    }

    public function suggest(CountryProfile $country): NamingContentSuggestion
    {
        $this->calls++;

        if (!$this->configured) {
            throw new NamingContentUnavailableException('fake suggester is not configured');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->answer ?? new NamingContentSuggestion();
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
}
