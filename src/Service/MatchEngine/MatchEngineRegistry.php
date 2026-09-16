<?php

namespace App\Service\MatchEngine;

use App\Enum\Competition\MatchEngineIdentifier;

/**
 * Tagged-iterator dispatch (see config/services.yaml's _instanceof binding on
 * MatchEngineInterface) — new pattern for this repo, no prior tagged-iterator use.
 */
class MatchEngineRegistry
{
    /** @param iterable<MatchEngineInterface> $engines */
    public function __construct(
        private readonly iterable $engines,
    ) {}

    public function resolveFor(MatchEngineIdentifier $identifier): MatchEngineInterface
    {
        foreach ($this->engines as $engine) {
            if ($engine->supports($identifier)) {
                return $engine;
            }
        }

        // A config error (a round's identifier has no registered engine), not a runtime/user error.
        throw new \LogicException("No match engine registered for identifier: {$identifier->value}");
    }
}
