<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

use App\Entity\Location\Location;

/**
 * Asks an AI provider for the geography the seeded data does not have.
 *
 * The interface exists because the provider is a deliberately open decision. Everything above
 * it — the entity, the lease, the queue, the validator, the admin screens — is written against
 * this and names no vendor, so swapping provider or model is one class and two env vars.
 *
 * Implementations MUST:
 *   - be safe to call concurrently (workers drain the enrichment queue in parallel);
 *   - throw rather than return a half-filled result on a transport or auth failure, so the
 *     handler can record outcome=error and distinguish "could not ask" from "asked, got
 *     nonsense". A silently empty result would look like the latter;
 *   - never write to the Location. Validation and persistence are not their job.
 *
 * Implementations MUST NOT assume their answer will be used. EnrichmentValidator rejects per
 * field, and an admin-locked field is skipped entirely.
 */
interface LocationEnricherInterface
{
    /**
     * @throws EnrichmentUnavailableException when the provider cannot be reached or is unconfigured
     */
    public function enrich(Location $location): EnrichmentResult;

    /** Short provider slug stored on every row this enricher produces, e.g. 'gemini'. */
    public function provider(): string;

    /** Exact model identifier, stored per row so a model change is visible per location. */
    public function model(): string;

    /**
     * Prompt revision, bumped whenever the prompt text changes.
     *
     * Stored on every attempt so a batch of odd answers can be traced to the prompt that
     * produced them, rather than guessed at from timestamps.
     */
    public function promptVersion(): string;

    /**
     * Cheap round-trip proving credentials and connectivity, for the admin screen.
     *
     * This exists because of a real incident in this codebase: a missing
     * FIREBASE_SERVICE_ACCOUNT_JSON silently discarded every push notification with no trace,
     * which is why FirebaseConnectionValidator and NotificationDebugController exist. An
     * unconfigured enricher must be visible before a 865-row sweep is started, not after.
     *
     * @return array{ok: bool, detail: string}
     */
    public function checkConnection(): array;
}
