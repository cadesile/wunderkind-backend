<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

/**
 * The provider could not be asked — unconfigured, unreachable, or refusing.
 *
 * Distinct from "asked, got names the validator refused", which leaves the stored pool exactly
 * as it was and is reported per list.
 */
final class NamePoolUnavailableException extends \RuntimeException
{
}
