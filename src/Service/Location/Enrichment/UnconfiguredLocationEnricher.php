<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

use App\Entity\Location\Location;

/**
 * The default enricher: refuses loudly, because no provider has been chosen yet.
 *
 * This class exists so that "no provider configured" is a VISIBLE state rather than a silent
 * one. The alternative — leaving LocationEnricherInterface unimplemented — breaks container
 * compilation and takes the whole admin down; the other alternative, an enricher that returns
 * an empty result, would look exactly like a provider that answered unhelpfully, and 865 rows
 * would quietly land in FAILED with no indication why.
 *
 * That distinction is not hypothetical here. A missing FIREBASE_SERVICE_ACCOUNT_JSON once
 * discarded every push notification in this codebase with zero trace, which is why
 * NotificationLog, NotificationLoggingSubscriber and FirebaseConnectionValidator all exist. So
 * this throws EnrichmentUnavailableException — recorded as outcome=error on a
 * LocationEnrichmentAttempt row, with the message below in `error_message` — and reports itself
 * as not-ok to the admin connection check.
 *
 * REPLACING IT: implement LocationEnricherInterface against the chosen provider and point the
 * App\Service\Location\Enrichment\LocationEnricherInterface alias in config/services.yaml at
 * it. Nothing else in the feature names a vendor.
 */
final class UnconfiguredLocationEnricher implements LocationEnricherInterface
{
    public const MESSAGE = 'No AI location enricher is configured. '
        . 'Set LOCATION_ENRICHER_PROVIDER, LOCATION_ENRICHER_MODEL and LOCATION_ENRICHER_API_KEY, '
        . 'and alias LocationEnricherInterface to a real implementation.';

    public function enrich(Location $location): EnrichmentResult
    {
        throw new EnrichmentUnavailableException(self::MESSAGE);
    }

    public function provider(): string
    {
        return 'unconfigured';
    }

    public function model(): string
    {
        return 'none';
    }

    public function promptVersion(): string
    {
        return 'v0';
    }

    public function checkConnection(): array
    {
        return ['ok' => false, 'detail' => self::MESSAGE];
    }
}
