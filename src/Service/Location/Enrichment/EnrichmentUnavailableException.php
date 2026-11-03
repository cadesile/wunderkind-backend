<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

/**
 * The provider could not be asked at all — unconfigured, unreachable, refused the credentials.
 *
 * Deliberately distinct from a response that failed validation. One means "we never found
 * out", recorded as outcome=error and worth retrying; the other means "we asked and the answer
 * was unusable", recorded as rejected and not worth retrying with the same prompt.
 */
class EnrichmentUnavailableException extends \RuntimeException
{
}
