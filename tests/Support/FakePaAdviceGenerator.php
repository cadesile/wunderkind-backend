<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\PersonalAssistant\PaAdviceGeneratorInterface;
use App\Service\PersonalAssistant\PaAdviceUnavailableException;

/** Records what it was asked and answers with whatever the test set. Never calls a provider. */
final class FakePaAdviceGenerator implements PaAdviceGeneratorInterface
{
    public bool $configured = true;

    public string $reply = 'Boss, the books are fine for now.';

    public ?string $failWith = null;

    /** @var list<array{system: string, user: string, timeout: int}> */
    public array $calls = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function generate(string $system, string $user, array $schema, int $timeoutSeconds): string
    {
        $this->calls[] = ['system' => $system, 'user' => $user, 'timeout' => $timeoutSeconds];

        if ($this->failWith !== null) {
            throw new PaAdviceUnavailableException($this->failWith, 'fake failure');
        }

        return $this->reply;
    }
}
