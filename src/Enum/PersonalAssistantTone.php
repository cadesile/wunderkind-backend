<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The template family the app renders a Personal Assistant's advice in when no AI wording is
 * available (offline, capped, provider down).
 *
 * THE APP OWNS THE TEMPLATES. Each case maps to `pa.advice.<code>.<tone>` keys on the device.
 * Adding a case here without adding those keys is safe — the app falls back to its neutral
 * variant — but the new tone only shows its own voice once the app ships the keys.
 */
enum PersonalAssistantTone: string
{
    case Dry     = 'dry';
    case Upbeat  = 'upbeat';
    case Precise = 'precise';

    /** @return array<string, string> label => value, for EasyAdmin ChoiceField */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[ucfirst($case->value)] = $case->value;
        }

        return $out;
    }
}
