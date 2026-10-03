<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Narrative content types whose text fields can carry per-language translations via
 * TranslationKey::$entityType. Not every column on these entities is translatable — see
 * NarrativeTranslationService::TRANSLATABLE_FIELDS for the exact field list per type.
 */
enum TranslatableEntityType: string
{
    case GAME_EVENT_TEMPLATE = 'game_event_template';
    case FACILITY_TEMPLATE   = 'facility_template';
    case EXCURSION           = 'excursion';
}
