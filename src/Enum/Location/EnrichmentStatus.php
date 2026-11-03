<?php

namespace App\Enum\Location;

/**
 * Where one location is in the AI-enrichment lifecycle.
 *
 * PENDING -> QUEUED is a claim-lease transition (Location::$enrichmentClaimedAt), which is
 * what collapses many simultaneous viewers of the same un-enriched location into a single
 * API call — only the caller whose conditional UPDATE affected a row dispatches the message.
 *
 * NEEDS_REVIEW is not a failure: it means some fields were accepted and some were rejected by
 * EnrichmentValidator, so the row explains its own exclusions rather than silently storing a
 * coordinate in the Atlantic. FAILED means nothing usable came back at all.
 */
enum EnrichmentStatus: string
{
    case PENDING      = 'pending';
    case QUEUED       = 'queued';
    case ENRICHED     = 'enriched';
    case NEEDS_REVIEW = 'needs_review';
    case FAILED       = 'failed';

    /** Statuses an admin sweep may re-queue; ENRICHED rows are never re-enriched. */
    public function isReEnrichable(): bool
    {
        return $this === self::PENDING || $this === self::FAILED || $this === self::NEEDS_REVIEW;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING      => 'Pending',
            self::QUEUED       => 'Queued',
            self::ENRICHED     => 'Enriched',
            self::NEEDS_REVIEW => 'Needs review',
            self::FAILED       => 'Failed',
        };
    }

    /**
     * Bootstrap contextual colour for the admin badge.
     *
     * NEEDS_REVIEW is `warning` and FAILED is `danger` on purpose: the first is an operator's
     * work queue, the second is a thing that broke. Rendering both the same would bury the
     * distinction the status exists to draw.
     */
    public function badgeColour(): string
    {
        return match ($this) {
            self::PENDING      => 'secondary',
            self::QUEUED       => 'info',
            self::ENRICHED     => 'success',
            self::NEEDS_REVIEW => 'warning',
            self::FAILED       => 'danger',
        };
    }
}
