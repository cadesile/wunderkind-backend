<?php

namespace App\Service\Competition;

/**
 * Every blocking reason, not just the first — the register/available endpoints need
 * to tell the player everything that's blocking them, not stop at the first miss.
 */
final class EligibilityResult
{
    /**
     * @param list<string> $reasons Legacy string reason codes for backward compatibility
     * @param list<array<string, mixed>> $structuredReasons Structured reason objects for viewer.eligibility.reasons
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $reasons,
        public readonly array $structuredReasons = [],
    ) {}
}
