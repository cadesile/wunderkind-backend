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
     * Admin asked the model for more town names for a country.
     *
     * Not an enrichment, but it shares everything a run record exists to answer — which
     * provider, which model, which country, which admin, and what it cost — so it reuses the
     * table rather than adding a near-identical second one.
     *
     * The counters mean something slightly different here, and only here:
     *   queued    = candidates the validator accepted
     *   succeeded = rows actually inserted
     *   failed    = candidates rejected, plus ones already held
     * `needsReview` is unused: a suggestion is taken or it is not, and whether the town is
     * real is settled later by enrichment, which records its own verdict.
     *
     * The backing value is short because trigger_type is VARCHAR(16); spelling it
     * 'admin_town_suggestion' would need a migration to widen the column for no gain.
     */
    case ADMIN_TOWN_SUGGESTION = 'town_suggest';
}
