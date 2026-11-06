<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * The model could not be asked, or answered with something unusable.
 *
 * Deliberately one exception for both: from a caller's point of view "the network was down"
 * and "a 200 came back containing no JSON" are the same event — we did not get an answer —
 * and both should be recorded as an error rather than as an unhelpful answer.
 *
 * Callers with their own established exception type (enrichment has one, and a great deal of
 * code already catches it) should catch this and rethrow as theirs. Making those types extend
 * this one would look tidier and would be a trap: a `catch (EnrichmentUnavailableException)`
 * does not catch a parent, so every existing catch site would quietly stop working.
 */
class AiUnavailableException extends \RuntimeException
{
}
