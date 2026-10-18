<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Appearance\KitColor;
use App\Enum\Appearance\TrophyFinish;
use App\Enum\Appearance\TrophyStyle;

/**
 * The one place a trophy config is defaulted, validated and migrated.
 *
 * A trophy config is the plain 5-key array the 16-bit trophy sprite's
 * `TrophyConfig` expects — `{style, finish, ribbons, ribbonPrimary,
 * ribbonSecondary}` — stored verbatim in a nullable `json` column on `League`
 * and `CompetitionTemplate` and emitted verbatim by every serializer, exactly
 * as `Player::$appearance` and `NpcClub::$identity` are. There is deliberately
 * no value object: the sprite owns the shape, so wrapping it here would be a
 * second definition to keep in step for no gain.
 *
 * Validation follows §5 of the sprite's integration prompt: keep known keys,
 * check enums, accept only `#rrggbb` colours, fall back to defaults, never
 * throw. A bad value is a config that renders as something sensible, not a 500
 * — the same "fail soft on client-interpreted JSON" posture the rest of this
 * codebase takes with sprite configs.
 *
 * Note there is no `random()` here on purpose. League and competition trophies
 * are either admin-chosen or come from GameConfig's league-tier defaults table,
 * so nothing server-side ever needs to roll one; the Randomise button in the
 * admin widget is `window.randomTrophyConfig()` from the compositor bundle.
 *
 * All-static and dependency-free, following CountryContentRegistry: GameConfig
 * normalises its own league-tier-defaults rows through fromLegacy(), and an
 * entity cannot be handed an injected service.
 */
final class TrophyConfigFactory
{
    /**
     * Mirrors the sprite's own DEFAULT_TROPHY_CONFIG. Kept in step by
     * TrophyConfigFactoryTest, which asserts it against the generated
     * compositor's exported default rather than trusting this copy.
     */
    public const DEFAULTS = [
        'style'           => 'classic',
        'finish'          => 'gold',
        'ribbons'         => true,
        'ribbonPrimary'   => '#c8202f',
        'ribbonSecondary' => '#f4f3ee',
    ];

    /**
     * Old `trophy-N` asset slug to a sprite style.
     *
     * There is no visual correspondence to preserve — the 15 retired files were
     * hand-drawn silhouettes and the 10 styles are procedurally drawn shapes —
     * so this spreads the slugs across the styles in order rather than
     * pretending to match artwork, which keeps the variety an admin had already
     * configured instead of collapsing every league onto `classic`. In the
     * real data only `trophy-1`..`trophy-8` were ever used (one per league
     * tier), so every tier lands on a distinct style and tier 1 — the top
     * division — keeps the flagship `classic` cup with its engraved medallion.
     *
     * @return array<int, string>
     */
    public static function legacyStyleOrder(): array
    {
        return array_map(static fn (TrophyStyle $s): string => $s->value, TrophyStyle::cases());
    }

    /** @return array{style:string,finish:string,ribbons:bool,ribbonPrimary:string,ribbonSecondary:string} */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Coerce anything into a renderable config. Unknown keys are dropped,
     * invalid values fall back to the default for that key.
     *
     * @return array{style:string,finish:string,ribbons:bool,ribbonPrimary:string,ribbonSecondary:string}
     */
    public static function validate(mixed $input): array
    {
        $in = is_array($input) ? $input : [];

        $style  = is_string($in['style'] ?? null) ? TrophyStyle::tryFrom($in['style']) : null;
        $finish = is_string($in['finish'] ?? null) ? TrophyFinish::tryFrom($in['finish']) : null;

        return [
            'style'   => ($style ?? TrophyStyle::from(self::DEFAULTS['style']))->value,
            'finish'  => ($finish ?? TrophyFinish::from(self::DEFAULTS['finish']))->value,
            // Only an absent key defaults; a present one is read loosely, so a
            // form post's "0"/"" or a JSON 1 behaves like the boolean it meant.
            'ribbons' => array_key_exists('ribbons', $in)
                ? self::looseBool($in['ribbons'])
                : self::DEFAULTS['ribbons'],
            'ribbonPrimary'   => self::hex($in['ribbonPrimary'] ?? null, self::DEFAULTS['ribbonPrimary']),
            'ribbonSecondary' => self::hex($in['ribbonSecondary'] ?? null, self::DEFAULTS['ribbonSecondary']),
        ];
    }

    /**
     * Null in, null out — "this league awards no trophy" is a real state that
     * several call sites depend on (ClubSpotlightService lists a title with no
     * artwork; GameConfig's tier defaults ship with none set), so an absent
     * config must never be silently defaulted into a visible one.
     *
     * @return array{style:string,finish:string,ribbons:bool,ribbonPrimary:string,ribbonSecondary:string}|null
     */
    public static function validateNullable(mixed $input): ?array
    {
        if ($input === null || $input === '' || $input === []) {
            return null;
        }

        return self::validate($input);
    }

    /**
     * Translate the retired `trophyImage`/`trophyColour` pair into a config.
     *
     * Used by the schema migration's backfill and by the world import, which
     * must still read export files written before the new component landed.
     * Both legacy fields are nullable, and "no design" meant "no trophy" — so
     * a null slug yields null even when a colour was set.
     *
     * @return array{style:string,finish:string,ribbons:bool,ribbonPrimary:string,ribbonSecondary:string}|null
     */
    public static function fromLegacy(?string $trophyImage, ?string $trophyColour): ?array
    {
        if ($trophyImage === null || $trophyImage === '') {
            return null;
        }

        if (preg_match('/^trophy-(\d+)$/', $trophyImage, $m) !== 1) {
            return null;
        }

        $order = self::legacyStyleOrder();
        $style = $order[((int) $m[1] - 1) % count($order)];

        return self::validate([
            'style'  => $style,
            'finish' => self::legacyFinish($trophyColour),
        ]);
    }

    /**
     * `gold_silver` is the only renamed value — the sprite calls it `twotone`.
     * `gold` and `silver` carry over untouched, which is why no admin loses a
     * choice in the migration.
     */
    public static function legacyFinish(?string $trophyColour): string
    {
        return match ($trophyColour) {
            'silver'      => TrophyFinish::SILVER->value,
            'gold_silver' => TrophyFinish::TWOTONE->value,
            default       => TrophyFinish::GOLD->value,
        };
    }

    /** @return list<array{value:string,label:string}> Ribbon colour choices — the shared 12-colour kit palette. */
    public static function ribbonColours(): array
    {
        return array_map(
            static fn (KitColor $c): array => [
                'value' => $c->value,
                'label' => ucwords(strtolower(str_replace('_', ' ', $c->name))),
            ],
            KitColor::cases(),
        );
    }

    /** Everything except the recognised "off" spellings counts as on. */
    private static function looseBool(mixed $v): bool
    {
        return !in_array($v, [null, false, 0, 0.0, '0', '', 'false', 'off', 'no', []], true);
    }

    /** Only `#rrggbb` survives; everything else falls back. Normalised to lower case so stored configs are uniform. */
    private static function hex(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : $fallback;
    }
}
