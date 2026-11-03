<?php

namespace App\Enum\Location;

/**
 * How far a country has progressed towards being playable.
 *
 * Deliberately NOT the same axis as Country::isGenerationCapable() (a property of the code,
 * fixed at deploy) or StarterConfig::$enabledCountries (whether players may found a club
 * there). This one tracks the readiness of the *location* data — see CountryReadinessService
 * and docs/world-generation/adding-a-country.md on why those notions stay apart.
 */
enum CountryProfileStatus: string
{
    /** Seeded, but its league pyramid and/or its locations are incomplete. */
    case DRAFT = 'draft';

    /** Eight league tiers and ability ranges exist; locations may still be un-enriched. */
    case TIER_READY = 'tier_ready';

    /** Everything ready — the only status GET /api/locations returns by default. */
    case LIVE = 'live';

    /** Withdrawn. Existing claims keep working; nothing new is offered. */
    case RETIRED = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT      => 'Draft',
            self::TIER_READY => 'Tier ready',
            self::LIVE       => 'Live',
            self::RETIRED    => 'Retired',
        };
    }

    public function badgeColour(): string
    {
        return match ($this) {
            self::DRAFT      => 'secondary',
            self::TIER_READY => 'info',
            self::LIVE       => 'success',
            self::RETIRED    => 'dark',
        };
    }
}
