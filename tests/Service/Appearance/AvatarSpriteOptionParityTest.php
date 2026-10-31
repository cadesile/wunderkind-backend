<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use App\Enum\Appearance\Face;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\Headwear;
use App\Enum\Appearance\Neckwear;
use App\Enum\Appearance\Outfit;
use App\Enum\Appearance\Tattoo;
use App\Service\Appearance\AppearanceGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * The backend's appearance vocabulary against the sprite's own, read out of the
 * GENERATED compositor bundle — the same technique TrophyConfigFactoryTest uses, and
 * for the same reason: a sprite drop that adds a hairstyle must not be able to leave
 * the backend silently unable to generate it.
 *
 * This is a drift guard, not a spec. The sprite is upstream; when it gains an option,
 * this test fails and the fix is to extend the enum and its weight table — never to
 * relax the assertion.
 *
 * Skips rather than fails when the bundle or node is unavailable, so a fresh checkout
 * and CI without node stay green (the parity that really matters is enforced inside
 * scripts/build-avatar-compositor.sh, which refuses to write a drifted bundle at all).
 */
class AvatarSpriteOptionParityTest extends TestCase
{
    private const COMPOSITOR = __DIR__ . '/../../../public/assets/avatar-compositor.js';

    /** @return array<string, mixed> */
    private function spriteOptions(): array
    {
        if (!is_file(self::COMPOSITOR)) {
            self::markTestSkipped('avatar-compositor.js not built — run scripts/build-avatar-compositor.sh.');
        }

        $script = sprintf(
            'global.window = {}; require(%s); process.stdout.write(JSON.stringify(window.AVATAR_SPRITE_OPTIONS));',
            json_encode(realpath(self::COMPOSITOR)),
        );

        exec('node -e ' . escapeshellarg($script) . ' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('node is not available to read the generated compositor.');
        }

        $decoded = json_decode(implode('', $out), true);

        if (!is_array($decoded) || !isset($decoded['hair'])) {
            self::markTestSkipped('avatar-compositor.js predates window.AVATAR_SPRITE_OPTIONS — rebuild it.');
        }

        return $decoded;
    }

    /**
     * Membership AND order. hairStyleWeights() combines a positional weight array with
     * HairStyle::cases(), so a case in the wrong position silently re-labels every
     * weight after it.
     */
    public function testHairStyleEnumMatchesTheSpriteList(): void
    {
        self::assertSame(
            $this->spriteOptions()['hair'],
            array_map(static fn (HairStyle $h): string => $h->value, HairStyle::cases()),
            'HairStyle must match the sprite\'s HAIR_STYLES exactly, in order.',
        );
    }

    public function testFaceEnumMatchesTheSpriteList(): void
    {
        self::assertSame(
            $this->spriteOptions()['face'],
            array_map(static fn (Face $f): string => $f->value, Face::cases()),
            'Face must match the sprite\'s FACES exactly, in order.',
        );
    }

    public function testOutfitEnumMatchesTheSpriteList(): void
    {
        self::assertSame(
            $this->spriteOptions()['outfit'],
            array_map(static fn (Outfit $o): string => $o->value, Outfit::cases()),
            'Outfit must match the sprite\'s OUTFITS exactly, in order.',
        );
    }

    /**
     * The AvatarExtras vocabulary, which the backend now models — same drift guard as
     * the three above, membership and order.
     */
    public function testTheExtrasEnumsMatchTheSpriteLists(): void
    {
        $sprite = $this->spriteOptions();

        self::assertSame(
            $sprite['headwear'],
            array_map(static fn (Headwear $h): string => $h->value, Headwear::cases()),
        );
        self::assertSame(
            $sprite['neckwear'],
            array_map(static fn (Neckwear $n): string => $n->value, Neckwear::cases()),
        );
        self::assertSame(
            $sprite['tattoos'],
            array_map(static fn (Tattoo $t): string => $t->value, Tattoo::cases()),
        );
    }

    /**
     * The morale→face ladder is still deliberately NOT modelled: `face` is a stored
     * cosmetic pick here, not a live mood readout (see CLAUDE.md). Unlike the extras,
     * which are now wired, this omission stands — so it is asserted rather than assumed.
     */
    public function testTheMoraleLadderIsStillUnmodelled(): void
    {
        self::assertCount(10, $this->spriteOptions()['moraleFaces'], 'ten equal morale bands');
        self::assertFalse(
            method_exists(AppearanceGeneratorService::class, 'faceForMorale'),
            'face is a stored cosmetic pick; wiring morale would reverse a documented decision',
        );
    }

    /**
     * Tattoo::visibleFor() is a verbatim port of the sprite's visibleTattoos(): ink lands
     * only on bare skin, so a staff member shows the arm pair ONLY in the polo or the
     * rolled-sleeve shirt, and a calf tattoo is players-only.
     */
    public function testVisibleTattoosMatchesTheSpriteRule(): void
    {
        $ids = static fn (array $ts): array => array_map(static fn (Tattoo $t): string => $t->value, $ts);

        self::assertSame(
            ['sleeve', 'band', 'hand', 'leg', 'neck', 'face'],
            $ids(Tattoo::visibleFor(true, null)),
            'a player shows all six',
        );

        self::assertSame(['hand', 'neck', 'face'], $ids(Tattoo::visibleFor(false, Outfit::COAT)));
        self::assertSame(['hand', 'neck', 'face'], $ids(Tattoo::visibleFor(false, Outfit::SUIT)));

        foreach ([Outfit::POLO, Outfit::SHIRT] as $bare) {
            self::assertSame(
                ['sleeve', 'band', 'hand', 'neck', 'face'],
                $ids(Tattoo::visibleFor(false, $bare)),
                $bare->value . ' leaves the forearms bare',
            );
            self::assertNotContains('leg', $ids(Tattoo::visibleFor(false, $bare)), 'a calf tattoo is players-only');
        }
    }

    /** Every face the morale ladder can produce must still be a face this backend knows. */
    public function testEveryMoraleFaceIsAKnownFace(): void
    {
        $known = array_map(static fn (Face $f): string => $f->value, Face::cases());

        foreach ($this->spriteOptions()['moraleFaces'] as $face) {
            self::assertContains($face, $known, "morale band face '{$face}' is not in the Face enum");
        }
    }

    /**
     * Both new weight tables are positional against their enums and must sum to 100 —
     * the same guard hairStyleWeights() already has.
     */
    public function testTheNewWeightTablesAlignWithTheirEnumsAndSumToOneHundred(): void
    {
        $svc = new AppearanceGeneratorService();

        $faces = $svc->faceWeights();
        self::assertSame(
            array_map(static fn (Face $f): string => $f->value, Face::cases()),
            array_keys($faces),
            'faceWeights() must be keyed in Face enum order.',
        );
        self::assertSame(100, array_sum($faces), 'faceWeights() should sum to 100');

        $outfits = $svc->outfitWeights();
        self::assertSame(
            array_map(static fn (Outfit $o): string => $o->value, Outfit::cases()),
            array_keys($outfits),
            'outfitWeights() must be keyed in Outfit enum order.',
        );
        self::assertSame(100, array_sum($outfits), 'outfitWeights() should sum to 100');
    }

    /**
     * The unhappy end of the V4 face set stays rare. A stored identity sprite that looks
     * distraught forever is a bug report, not characterisation — same reasoning as
     * GINGER's 5% in hairColorWeights().
     */
    public function testTheUnhappyFacesAreRare(): void
    {
        $weights = (new AppearanceGeneratorService())->faceWeights();

        foreach ([Face::GLUM, Face::MISERABLE, Face::DISTRAUGHT] as $face) {
            self::assertLessThanOrEqual(
                2,
                $weights[$face->value],
                "{$face->value} should stay rare in generated staff appearances",
            );
            self::assertGreaterThan(0, $weights[$face->value], 'but reachable, since the admin picker offers it');
        }
    }
}
