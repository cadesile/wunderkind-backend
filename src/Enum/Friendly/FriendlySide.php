<?php

declare(strict_types=1);

namespace App\Enum\Friendly;

/**
 * Which of a friendly's two participants a row belongs to.
 *
 * Deliberately NOT 'home'/'away'. Those describe the *venue* — FriendlyChallenge::$venue
 * says where the challenger plays, so which participant is home flips per challenge. Keying
 * stored squad rows by venue would therefore mean the same row meant different things on
 * different challenges. Challenger and acceptor are fixed for the life of a challenge.
 */
enum FriendlySide: string
{
    case CHALLENGER = 'challenger';
    case ACCEPTOR   = 'acceptor';
}
