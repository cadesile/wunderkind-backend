<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GameConfig;
use App\Enum\Appearance\TrophyFinish;
use App\Enum\Appearance\TrophyStyle;
use App\Service\TrophyConfigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the trophy config contract on three fronts:
 *
 *  1. The PHP enums and defaults must not drift from the trophy sprite the app and
 *     the admin both render with. A config is handed to composeTrophySvg() verbatim,
 *     so a case this backend can store but the sprite cannot draw is a blank trophy
 *     with no error anywhere — exactly the failure mode the generated compositor's
 *     own parity gate exists to prevent one layer down. These tests read the
 *     generated bundle's exported vocabulary rather than trusting a second copy.
 *  2. Validation must never throw and never emit an unrenderable value, since
 *     TrophyConfigFactory sits in front of admin form posts, the world import and
 *     migrated legacy rows alike.
 *  3. The legacy `trophy-N` + `gold_silver` mapping must stay stable: it is baked
 *     into a shipped migration's backfill, so changing it silently reinterprets
 *     trophies that have already been converted in production.
 */
class TrophyConfigFactoryTest extends TestCase
{
    private const COMPOSITOR = __DIR__ . '/../../public/assets/trophy-compositor.js';

    /**
     * The generated compositor's `window.*` exports, read by running the real bundle.
     *
     * Skips rather than fails when node is unavailable: the bundle's own build script
     * already gates on the sprite's pixel-hash fixture, so this is a cross-language
     * drift check, not the primary guard — and it must not turn a PHP-only CI box red.
     *
     * @return array<string, mixed>
     */
    private function spriteVocabulary(): array
    {
        if (!is_file(self::COMPOSITOR)) {
            self::markTestSkipped('trophy-compositor.js not built — run scripts/build-trophy-compositor.sh.');
        }

        $script = sprintf(
            'global.window = {}; require(%s); process.stdout.write(JSON.stringify({'
            . 'styles: window.TROPHY_SPRITE_OPTIONS.TROPHY_STYLES,'
            . 'finishes: window.TROPHY_SPRITE_OPTIONS.TROPHY_FINISHES,'
            . 'colors: window.TROPHY_SPRITE_OPTIONS.KIT_COLORS,'
            . 'defaults: window.DEFAULT_TROPHY_CONFIG}));',
            json_encode(realpath(self::COMPOSITOR)),
        );

        exec('node -e ' . escapeshellarg($script) . ' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('node is not available to read the generated compositor.');
        }

        $decoded = json_decode(implode('', $out), true);
        self::assertIsArray($decoded, 'Could not read the compositor exports.');

        return $decoded;
    }

    public function testStyleEnumMatchesTheSpriteStyleList(): void
    {
        $sprite = $this->spriteVocabulary();

        self::assertSame(
            array_column($sprite['styles'], 'id'),
            array_map(static fn (TrophyStyle $s): string => $s->value, TrophyStyle::cases()),
            'TrophyStyle must list exactly the sprite\'s styles, in the same order — '
            . 'the order is load-bearing for the legacy slug mapping.',
        );

        self::assertSame(
            array_column($sprite['styles'], 'label'),
            array_map(static fn (TrophyStyle $s): string => $s->label(), TrophyStyle::cases()),
            'Admin labels must match the sprite\'s own, so the two UIs name a design alike.',
        );
    }

    public function testFinishEnumMatchesTheSpriteFinishList(): void
    {
        $sprite = $this->spriteVocabulary();

        self::assertSame(
            array_column($sprite['finishes'], 'id'),
            array_map(static fn (TrophyFinish $f): string => $f->value, TrophyFinish::cases()),
        );
        self::assertSame(
            array_column($sprite['finishes'], 'label'),
            array_map(static fn (TrophyFinish $f): string => $f->label(), TrophyFinish::cases()),
        );
    }

    public function testDefaultsMatchTheSpriteDefaults(): void
    {
        $sprite = $this->spriteVocabulary();

        self::assertSame(
            $sprite['defaults'],
            TrophyConfigFactory::defaults(),
            'A null trophy renders from these defaults the moment an admin picks a style, '
            . 'so they must be the sprite\'s own.',
        );
    }

    public function testRibbonPaletteMatchesTheSpriteKitColours(): void
    {
        $sprite = $this->spriteVocabulary();

        self::assertSame(
            $sprite['colors'],
            array_column(TrophyConfigFactory::ribbonColours(), 'value'),
            'Ribbon colours reuse KitColor precisely because the sprite\'s ribbon palette '
            . 'IS the shared kit palette — a divergence means one of the two moved.',
        );
    }

    /** @return array<string, array{mixed}> */
    public static function junkProvider(): array
    {
        return [
            'null'               => [null],
            'empty array'        => [[]],
            'scalar'             => ['classic'],
            'unknown enum'       => [['style' => 'spoon', 'finish' => 'bronze']],
            'non-string enum'    => [['style' => 42, 'finish' => ['gold']]],
            'bad hex'            => [['ribbonPrimary' => 'red', 'ribbonSecondary' => '#ABC']],
            'unknown keys only'  => [['wibble' => true, 'style' => null]],
            'nested nonsense'    => [['style' => ['classic'], 'ribbons' => ['yes']]],
        ];
    }

    #[DataProvider('junkProvider')]
    public function testValidateAlwaysReturnsSomethingRenderable(mixed $input): void
    {
        $config = TrophyConfigFactory::validate($input);

        self::assertSame(
            ['style', 'finish', 'ribbons', 'ribbonPrimary', 'ribbonSecondary'],
            array_keys($config),
            'Unknown keys must be dropped and every known key present, in a stable order.',
        );
        self::assertNotNull(TrophyStyle::tryFrom($config['style']));
        self::assertNotNull(TrophyFinish::tryFrom($config['finish']));
        self::assertIsBool($config['ribbons']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $config['ribbonPrimary']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $config['ribbonSecondary']);
    }

    public function testValidKeysSurviveUntouched(): void
    {
        self::assertSame(
            [
                'style' => 'winged', 'finish' => 'twotone', 'ribbons' => false,
                'ribbonPrimary' => '#1f4fb8', 'ribbonSecondary' => '#f2c230',
            ],
            TrophyConfigFactory::validate([
                'style' => 'winged', 'finish' => 'twotone', 'ribbons' => false,
                'ribbonPrimary' => '#1f4fb8', 'ribbonSecondary' => '#f2c230',
            ]),
        );
    }

    /** Uppercase hex renders identically but is normalised so stored configs stay uniform. */
    public function testHexIsLowercased(): void
    {
        $config = TrophyConfigFactory::validate(['ribbonPrimary' => '#AABBCC']);

        self::assertSame('#aabbcc', $config['ribbonPrimary']);
    }

    /**
     * "No trophy" is a real state — League::$trophy is nullable and the honours
     * board, the tier-defaults table and the admin widget all depend on it staying
     * null rather than being defaulted into a visible cup.
     */
    public function testValidateNullablePreservesAbsence(): void
    {
        self::assertNull(TrophyConfigFactory::validateNullable(null));
        self::assertNull(TrophyConfigFactory::validateNullable([]));
        self::assertNull(TrophyConfigFactory::validateNullable(''));
        self::assertNotNull(TrophyConfigFactory::validateNullable(['style' => 'bowl']));
    }

    /** An absent `ribbons` key keeps the default; a present one is read loosely. */
    public function testRibbonsKeyPresenceSemantics(): void
    {
        self::assertTrue(TrophyConfigFactory::validate(['style' => 'bowl'])['ribbons']);

        foreach ([false, 0, '0', '', 'false', 'off', 'no', null] as $off) {
            self::assertFalse(
                TrophyConfigFactory::validate(['ribbons' => $off])['ribbons'],
                sprintf('%s should read as ribbons off.', var_export($off, true)),
            );
        }

        foreach ([true, 1, '1', 'on', 'yes'] as $on) {
            self::assertTrue(
                TrophyConfigFactory::validate(['ribbons' => $on])['ribbons'],
                sprintf('%s should read as ribbons on.', var_export($on, true)),
            );
        }
    }

    /** @return array<string, array{?string, ?string, ?string, ?string}> slug, colour, expected style, expected finish */
    public static function legacyProvider(): array
    {
        return [
            // The eight slugs that were actually in use, one per league tier.
            'tier 1' => ['trophy-1', 'gold', 'classic', 'gold'],
            'tier 2' => ['trophy-2', 'gold_silver', 'goblet', 'twotone'],
            'tier 3' => ['trophy-3', 'silver', 'loving', 'silver'],
            'tier 4' => ['trophy-4', 'gold', 'urn', 'gold'],
            'tier 5' => ['trophy-5', 'gold_silver', 'chalice', 'twotone'],
            'tier 6' => ['trophy-6', 'silver', 'tall', 'silver'],
            'tier 7' => ['trophy-7', 'gold_silver', 'winged', 'twotone'],
            'tier 8' => ['trophy-8', 'gold', 'league', 'gold'],
            // 9 and 10 finish the first pass; 11 onwards wrap.
            'slug 10 ends the pass' => ['trophy-10', null, 'bowl', 'gold'],
            'slug 11 wraps'         => ['trophy-11', null, 'classic', 'gold'],
            'slug 15 wraps'         => ['trophy-15', 'silver', 'chalice', 'silver'],
            // An unset colour was legal and meant "not configured" — gold is the
            // sprite's own default and the closest thing to the old unfiltered look.
            'no colour' => ['trophy-4', null, 'urn', 'gold'],
        ];
    }

    #[DataProvider('legacyProvider')]
    public function testLegacySlugMapping(?string $slug, ?string $colour, ?string $style, ?string $finish): void
    {
        $config = TrophyConfigFactory::fromLegacy($slug, $colour);

        self::assertNotNull($config);
        self::assertSame($style, $config['style']);
        self::assertSame($finish, $config['finish']);
        self::assertTrue($config['ribbons'], 'Migrated trophies keep ribbons on — see the migration docblock.');
    }

    /** No design meant no trophy, even when a colour was set. */
    public function testLegacyWithoutASlugIsNull(): void
    {
        self::assertNull(TrophyConfigFactory::fromLegacy(null, 'gold'));
        self::assertNull(TrophyConfigFactory::fromLegacy('', 'gold'));
        self::assertNull(TrophyConfigFactory::fromLegacy('trophy-abc', 'gold'));
        self::assertNull(TrophyConfigFactory::fromLegacy('classic', 'gold'));
    }

    /**
     * GameConfig folds a legacy tier-defaults row on read and converges it on write.
     *
     * That column is plain stored JSON, so rows saved before the trophy component
     * changed still carry `trophyImage`/`trophyColour` — as does any config export
     * file taken back then, and `ConfigImportExportService` round-trips this field
     * through these very accessors (it prefers `get`/`set` over raw property
     * access). Without the fold, opening the admin's League Tier Defaults screen
     * once and saving would silently drop every tier's trophy.
     */
    public function testGameConfigFoldsLegacyTierDefaultRows(): void
    {
        $config = new GameConfig();
        $config->setLeagueTierDefaults([
            '1' => [
                'promotionSpots' => null, 'tvDeal' => 100, 'prizeMoney' => 100,
                'leaguePositionPot' => 100, 'sponsorCount' => 0,
                'trophyImage' => 'trophy-2', 'trophyColour' => 'gold_silver',
            ],
        ]);

        $row = $config->getLeagueTierDefaults()['1'];

        self::assertSame('goblet', $row['trophy']['style']);
        self::assertSame('twotone', $row['trophy']['finish']);
        self::assertArrayNotHasKey('trophyImage', $row, 'the legacy keys must not survive the fold');
        self::assertArrayNotHasKey('trophyColour', $row);
        self::assertSame(100, $row['tvDeal'], 'non-trophy keys must pass through untouched');

        // getLeagueTierDefaultsForTier() reads the same folded shape.
        self::assertSame($row['trophy'], $config->getLeagueTierDefaultsForTier(1)['trophy']);
    }

    /** A legacy row with no design set stays "no trophy" rather than gaining a default cup. */
    public function testGameConfigFoldsLegacyRowWithNoDesignToNull(): void
    {
        $config = new GameConfig();
        $config->setLeagueTierDefaults([
            '3' => ['sponsorCount' => 0, 'trophyImage' => null, 'trophyColour' => 'gold'],
        ]);

        self::assertNull($config->getLeagueTierDefaults()['3']['trophy']);
    }

    /** Every slug the old admin could store must map to a style the sprite can draw. */
    public function testEveryLegacySlugMapsToARealStyle(): void
    {
        for ($n = 1; $n <= 15; $n++) {
            $config = TrophyConfigFactory::fromLegacy("trophy-$n", 'gold');

            self::assertNotNull($config, "trophy-$n must convert.");
            self::assertNotNull(
                TrophyStyle::tryFrom($config['style']),
                "trophy-$n mapped to an unknown style.",
            );
        }
    }
}
