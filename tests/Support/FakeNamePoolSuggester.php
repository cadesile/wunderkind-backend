<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Location\CountryProfile;
use App\Service\CountryContent\Suggestion\NamePoolSuggesterInterface;
use App\Service\CountryContent\Suggestion\NamePoolSuggestion;
use App\Service\CountryContent\Suggestion\NamePoolUnavailableException;

/**
 * A NamePoolSuggesterInterface that answers from a script.
 *
 * Same reason as the other fakes here: no test may make a real provider call — it would be
 * slow, non-deterministic and billed.
 *
 * It also RECORDS WHAT IT WAS ASKED, which the others do not need to. Half of what matters
 * about a top-up is the question: the shortfall, not the target, and the names already held
 * as exclusions. Those are assertions about the ask, so the ask has to be kept.
 */
final class FakeNamePoolSuggester implements NamePoolSuggesterInterface
{
    /** @var array<string, list<string>> */
    private array $answers = [];

    private ?\Throwable $failure = null;

    private bool $configured = true;

    /** @var list<array{list: string, exclude: list<string>, want: int}> */
    private array $asks = [];

    /** @param list<string> $names */
    public function answer(string $list, array $names): self
    {
        $this->answers[$list] = $names;

        return $this;
    }

    /**
     * Answer with $count generated names, for the cases where the content is irrelevant and
     * only the count is being asserted.
     */
    public function answerWith(string $list, int $count, string $prefix = 'Name'): self
    {
        $names = [];

        for ($i = 1; $i <= $count; $i++) {
            $names[] = $prefix . $i;
        }

        return $this->answer($list, $names);
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

    public function suggest(CountryProfile $country, string $list, array $exclude, int $want): NamePoolSuggestion
    {
        $this->asks[] = ['list' => $list, 'exclude' => $exclude, 'want' => $want];

        if (!$this->configured) {
            throw new NamePoolUnavailableException('fake suggester is not configured');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new NamePoolSuggestion($this->answers[$list] ?? [], 11, 22);
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

    /** @return list<array{list: string, exclude: list<string>, want: int}> */
    public function asks(): array
    {
        return $this->asks;
    }

    /** @return array{list: string, exclude: list<string>, want: int} */
    public function lastAsk(): array
    {
        if ($this->asks === []) {
            throw new \LogicException('Nothing was asked of the fake suggester.');
        }

        return $this->asks[\count($this->asks) - 1];
    }

    public function calls(): int
    {
        return \count($this->asks);
    }
}
