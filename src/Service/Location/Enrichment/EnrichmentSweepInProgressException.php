<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

/**
 * A sweep was requested while a previous one is still draining.
 *
 * Its own exception type rather than a boolean return, because the two callers want different
 * things: the admin controller turns it into a flash the operator can read, and the CLI turns
 * it into a non-zero exit so a script does not quietly double-spend.
 */
class EnrichmentSweepInProgressException extends \RuntimeException
{
}
