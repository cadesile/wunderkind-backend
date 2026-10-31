<?php
namespace App\Enum\Appearance;

/**
 * Must stay in the sprite's own HAIR_STYLES order — AppearanceGeneratorService::
 * hairStyleWeights() combines a positional weight array with HairStyle::cases(),
 * so a case added out of order silently re-labels every weight after it, and a
 * case added without extending that array is an array_combine() count error.
 * That is deliberate: it makes a drifting enum fail loudly rather than quietly.
 *
 * The seven below CURTAINS were added by the sprite's V4 drop (2026-10-31).
 * LOCS and BOB cover the ears, so the sprite hides an earring under them.
 */
enum HairStyle: string
{
    case BALD     = 'bald';
    case BUZZ     = 'buzz';
    case CROP     = 'crop';
    case QUIFF    = 'quiff';
    case MOHAWK   = 'mohawk';
    case AFRO     = 'afro';
    case LONG     = 'long';
    case BUN      = 'bun';
    case CORNROWS = 'cornrows';
    case CURTAINS = 'curtains';
    case SIDEPART = 'sidepart';
    case FLATTOP  = 'flattop';
    case SPIKY    = 'spiky';
    case PONYTAIL = 'ponytail';
    case LOCS     = 'locs';
    case BOB      = 'bob';
}
