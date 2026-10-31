<?php
namespace App\Enum\Appearance;

/**
 * Fixed expression, stored at generation time. Replaces the old design's
 * faceShape/eyeShape/noseType and its live morale-driven eyes-and-mouth
 * rendering — the sprite draws expression as one directly configured field,
 * not something computed from morale at render time.
 */
enum Face: string
{
    case NEUTRAL    = 'neutral';
    case HAPPY      = 'happy';
    case WINK       = 'wink';
    case FOCUSED    = 'focused';
    case SHOUT      = 'shout';
    case SAD        = 'sad';
    case FRUSTRATED = 'frustrated';
    case ANGRY      = 'angry';
    case COOL       = 'cool';
    // Added by the sprite's V4 drop (2026-10-31). Six of these are the ends of
    // the sprite's own morale ladder (MORALE_FACES), but the backend does NOT
    // couple face to morale — see the class note below and CLAUDE.md.
    case CONTENT    = 'content';
    case CHEERFUL   = 'cheerful';
    case ELATED     = 'elated';
    case GLUM       = 'glum';
    case MISERABLE  = 'miserable';
    case DISTRAUGHT = 'distraught';
}
