<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

/**
 * The provider could not be asked — unconfigured, unreachable, or refusing.
 *
 * Distinct from "asked, got something the validator refused", which leaves the country's
 * stored content exactly as it was and is reported field by field.
 */
final class NamingContentUnavailableException extends \RuntimeException
{
}
