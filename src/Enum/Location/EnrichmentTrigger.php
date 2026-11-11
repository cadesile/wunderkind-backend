<?php

namespace App\Enum\Location;

/** What caused an enrichment run. Recorded so a surprise API bill has a cause. */
enum EnrichmentTrigger: string
{
    /** A player opened an un-enriched location and the read path claimed it. */
    case LAZY = 'lazy';

    /** Admin enriched one location from its detail page. */
    case ADMIN_SINGLE = 'admin_single';

    /** Admin enriched a selection from the CRUD index. */
    case ADMIN_SUBSET = 'admin_subset';

    /** Admin enriched everything still outstanding, for one country or globally. */
    case ADMIN_ALL = 'admin_all';

    /**
     * One synchronous call asking the model for more town names for a country.
     *
     * Not an enrichment, but it shares everything a run record exists to answer — which
     * provider, which model, which country, which admin, and what it cost — so it reuses the
     * table rather than adding a near-identical second one.
     *
     * The counters mean something different here:
     *   queued    = candidates the validator accepted
     *   succeeded = rows actually inserted
     *   failed    = candidates rejected, plus ones already held
     * `needsReview` is unused: a suggestion is taken or it is not, and whether the town is
     * real is settled later by enrichment, which records its own verdict.
     *
     * NOW THE CLI PATH ONLY (app:locations:suggest-towns, which also supports --dry-run). The
     * admin screen used to use it and timed out doing so; it queues batches instead and records
     * them under ADMIN_TOWN_BATCH. This case keeps its original meaning precisely so the rows
     * already in the table still mean what they said — a run's counters must not change
     * meaning retroactively just because the screen that wrote them moved on.
     *
     * The backing value is short because trigger_type is VARCHAR(16); spelling it
     * 'admin_town_suggestion' would need a migration to widen the column for no gain.
     */
    case ADMIN_TOWN_SUGGESTION = 'town_suggest';

    /**
     * Admin queued a batched town pull from the enrichment screen.
     *
     * COUNTERS ARE BATCHES HERE, NOT TOWNS:
     *   queued    = batches dispatched
     *   succeeded = batches that ran
     *   failed    = batches that gave up after their own retries
     *
     * It cannot be otherwise: `queued` is written at dispatch, before anything has been asked,
     * and how many towns a run will accept is unknowable then. It is also what lets a run close
     * itself — LocationEnrichmentRunRepository::recordBatchOutcome() completes the run on the
     * tick where settled batches reach `queued`, so nothing has to poll.
     *
     * Towns added live on the TownSuggestionBatch rows and are summed for display. So a row
     * here reading "6 / 6" means six batches, and the towns figure is on the screen's own card
     * rather than in this table's columns.
     */
    case ADMIN_TOWN_BATCH = 'town_batch';

    /**
     * Admin asked the model for one COUNTRY's own facts — its bounding box, land band and
     * reference metadata — from the enrichment screen or app:locations:enrich-country.
     *
     * Not a town enrichment, but it shares everything a run record exists to answer: which
     * provider, which model, which country, which admin, and when. Same reuse of this table
     * as ADMIN_TOWN_SUGGESTION above, and for the same reason.
     *
     * COUNTERS ARE FIELDS HERE, NOT ROWS:
     *   queued    = fields the model answered with something
     *   succeeded = fields actually written to country_profile
     *   failed    = fields the validator refused
     *
     * `needsReview` is unused, deliberately. Fields left alone because the country already
     * had a value are reported in the admin flash, but they are not a review queue — nobody
     * needs to look at them, and putting them in the column the screen labels REVIEW would
     * say the opposite. A country field is either stored or refused; whether it is BELIEVABLE
     * is settled by an operator reading the country's own form, which is twelve values long.
     *
     * The run is written COMPLETE, in one go, because the call is synchronous — there is no
     * queue to drain and no later tick to close it.
     *
     * NO ATTEMPT ROW IS WRITTEN. location_enrichment_attempt.location_id is NOT NULL and an
     * attempt means nothing without a location; widening that for a dozen country fields a
     * human reads at a glance would weaken a table whose shape is load-bearing. The
     * consequence, knowingly accepted: these calls do not appear in the enrichment screen's
     * 30-day token figure, which sums attempt rows — the same gap the two triggers above have.
     *
     * The backing value is short because trigger_type is VARCHAR(16).
     */
    case ADMIN_COUNTRY_PROFILE = 'country_prof';
}
