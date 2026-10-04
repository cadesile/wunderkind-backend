<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when StarterPackService::initialize() draws zero players for a club's nationality
 * (and the foreign-fallback draw also comes up empty) — e.g. a brand-new country whose pool
 * hasn't been warmed yet on this environment. Deliberately thrown *before* any pool entities
 * are consumed/removed and *before* Club::$starterInitializedAt is set, so the attempt leaves
 * no trace and POST /api/initialize/starter is safely retriable once the pool has players —
 * unlike letting it silently mark the club initialized with an empty squad, which previously
 * left the club stuck with no players and no way to retry.
 */
class InsufficientStarterPoolException extends \RuntimeException
{
    public function __construct(string $nationality)
    {
        parent::__construct(sprintf(
            'No eligible players found in the pool for nationality "%s" (including foreign fallback). The pool likely needs warming for this country.',
            $nationality,
        ));
    }
}
