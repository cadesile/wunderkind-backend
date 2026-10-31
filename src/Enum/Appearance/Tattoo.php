<?php
namespace App\Enum\Appearance;

/**
 * Ink, which the sprite draws only on BARE SKIN — so which of these can actually show
 * depends on the role and, for staff, on the outfit. See visibleFor().
 *
 * Part of AvatarExtras; stored flat in `appearance` as a list of these values. Any
 * combination is legal and order is not significant.
 */
enum Tattoo: string
{
    case SLEEVE = 'sleeve';
    case BAND   = 'band';
    case HAND   = 'hand';
    case LEG    = 'leg';
    case NECK   = 'neck';
    case FACE   = 'face';

    /** Admin-facing labels, matching the sprite's own strings rather than the case names. */
    public function label(): string
    {
        return match ($this) {
            self::SLEEVE => 'Sleeve',
            self::BAND   => 'Forearm',
            self::HAND   => 'Knuckles',
            self::LEG    => 'Calf',
            self::NECK   => 'Neck',
            self::FACE   => 'Teardrop',
        };
    }

    /**
     * The tattoos a role + outfit can actually show — a verbatim port of the sprite's
     * own visibleTattoos(), which the web builder uses to hide ink the outfit covers.
     *
     * A player shows all six. Staff show only the ones on skin their outfit leaves
     * bare: knuckles, neck and teardrop always, plus the arm pair when the outfit is
     * the polo or the rolled-sleeve shirt. LEG is players-only, which falls out of the
     * same rule.
     *
     * @return Tattoo[]
     */
    public static function visibleFor(bool $isPlayer, ?Outfit $outfit): array
    {
        if ($isPlayer) {
            return self::cases();
        }

        $bareForearms = $outfit === Outfit::POLO || $outfit === Outfit::SHIRT;

        return array_values(array_filter(self::cases(), static fn (self $t): bool => match ($t) {
            self::HAND, self::NECK, self::FACE => true,
            self::SLEEVE, self::BAND           => $bareForearms,
            self::LEG                          => false,
        }));
    }
}
