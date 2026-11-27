<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

/** One AI call that words a Personal Assistant's advice. Tests swap in a fake. */
interface PaAdviceGeneratorInterface
{
    public function isConfigured(): bool;

    /**
     * @param array<string, mixed> $schema
     *
     * @return string the advice text, untrimmed
     *
     * @throws PaAdviceUnavailableException
     */
    public function generate(string $system, string $user, array $schema, int $timeoutSeconds): string;
}
