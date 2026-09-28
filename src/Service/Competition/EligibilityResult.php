<?php

namespace App\Service\Competition;

/**
 * Every blocking reason, not just the first — the register/available endpoints need
 * to tell the player everything that's blocking them, not stop at the first miss.
 */
final class EligibilityResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $reasons,
    ) {}
}
