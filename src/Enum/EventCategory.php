<?php

namespace App\Enum;

enum EventCategory: string
{
    case PLAYER          = 'player';
    case FACILITY        = 'facility';
    case STAFF           = 'staff';
    case FINANCE         = 'finance';
    case NPC_INTERACTION = 'NPC_INTERACTION';
    case GUARDIAN        = 'GUARDIAN';
    case MATCH           = 'MATCH';
    case PRESS              = 'press';
    case PLAYER_REPUTATION  = 'player_reputation';
    case PLAYER_MILESTONE   = 'player_milestone';
    case PLAYER_MORALE      = 'player_morale';
    case PLAYER_FORM        = 'player_form';

    /**
     * Human label for admin dropdowns. The backing values are inconsistently cased for
     * historical reasons (three are UPPERCASE), so ucfirst() alone produced labels like
     * "NPC_INTERACTION" and "Player_reputation".
     */
    public function label(): string
    {
        return match ($this) {
            self::PLAYER             => 'Player',
            self::FACILITY           => 'Facility',
            self::STAFF              => 'Staff',
            self::FINANCE            => 'Finance',
            self::NPC_INTERACTION    => 'NPC interaction',
            self::GUARDIAN           => 'Guardian',
            self::MATCH              => 'Match',
            self::PRESS              => 'Press',
            self::PLAYER_REPUTATION  => 'Player reputation',
            self::PLAYER_MILESTONE   => 'Player milestone',
            self::PLAYER_MORALE      => 'Player morale',
            self::PLAYER_FORM        => 'Player form',
        };
    }

    /**
     * Categories routed to PlayerEventEngine on the client, which reads
     * `impacts.stat_changes` only — a flat `[{target, delta}]` array is silently ignored.
     */
    public function requiresStatChanges(): bool
    {
        return in_array($this, [
            self::PLAYER_REPUTATION,
            self::PLAYER_MILESTONE,
            self::PLAYER_MORALE,
            self::PLAYER_FORM,
        ], true);
    }
}
