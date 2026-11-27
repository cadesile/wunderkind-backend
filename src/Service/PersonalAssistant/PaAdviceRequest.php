<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

/**
 * A validated, sanitised advice request. Built only by PaAdviceRequestParser, so anything
 * holding one has already passed the allowlists.
 */
final readonly class PaAdviceRequest
{
    /**
     * @param array<string, scalar>                                                      $context
     * @param list<array{code: string, severity: string, facts: array<string, scalar>}> $findings
     */
    public function __construct(
        public string $personalityId,
        public string $topic,
        public string $locale,
        public array $context,
        public array $findings,
    ) {}
}
