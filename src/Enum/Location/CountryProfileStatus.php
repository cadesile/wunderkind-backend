<?php

namespace App\Enum\Location;

/**
 * How far a country has progressed towards being playable — and, at LIVE, whether it is.
 *
 * TWO AXES NOW, NOT THREE. This used to track only the readiness of the *location* data,
 * with StarterConfig::$enabledCountries deciding separately whether players could found a
 * club there. That second list is gone: a country is offered to players exactly when its
 * profile is LIVE (CountryProfileRepository::liveCountryCodes()), because a hand-ticked list
 * could and did disagree with the readiness it was meant to describe — ticked while the
 * profile was still DRAFT, or left un-ticked long after the country went live.
 *
 * STILL NOT THE SAME AXIS as Country::isGenerationCapable(), which is a property of the code
 * fixed at deploy: what the generator can build is not what an operator has chosen to open.
 *
 * REACHING LIVE IS GATED. CountryReadinessService decides whether a country may be promoted,
 * and all three ways in — the Countries CRUD screen, the locations dashboard's GO LIVE, and
 * app:country:bootstrap --enable — go through CountryGoLiveService so they agree.
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
