<?php

namespace App\Enum;

/**
 * MANUAL groups resolve through the AudienceGroupMember join table.
 * DYNAMIC groups are evaluated live at poll time by AudienceCriteriaEvaluator against
 * criteriaPayload — there is no materialised membership and no refresh job.
 */
enum AudienceCriteriaType: string
{
    case MANUAL  = 'manual';
    case DYNAMIC = 'dynamic';
}
