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
}
