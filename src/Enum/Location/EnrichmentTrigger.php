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
}
