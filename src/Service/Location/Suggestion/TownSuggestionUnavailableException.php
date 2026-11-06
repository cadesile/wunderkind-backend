<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

/** The suggester could not be asked, or answered with something unusable. */
final class TownSuggestionUnavailableException extends \RuntimeException
{
}
