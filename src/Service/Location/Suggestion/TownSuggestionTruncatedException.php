<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

/**
 * The model ran out of output budget before finishing the town list.
 *
 * Separate from TownSuggestionUnavailableException so the batch handler can stop retrying:
 * truncation is deterministic, so three attempts buy three identical answers at three times
 * the price. See AiReplyTruncatedException for the full reasoning.
 *
 * NOT a subclass of TownSuggestionUnavailableException, which is final and widely caught. The
 * handler catches both explicitly, which is the honest way to say the two are different.
 */
final class TownSuggestionTruncatedException extends \RuntimeException
{
}
