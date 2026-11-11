<?php

declare(strict_types=1);

namespace App\Service\Location\CountryEnrichment;

/**
 * The provider could not be asked at all — unconfigured, unreachable, or refusing.
 *
 * Deliberately distinct from "asked, got nonsense", which is a validator verdict and leaves
 * the country's stored values exactly as they were. Mirrors EnrichmentUnavailableException,
 * and exists separately so a caller catching one cannot accidentally swallow the other.
 */
final class CountryEnrichmentUnavailableException extends \RuntimeException
{
}
