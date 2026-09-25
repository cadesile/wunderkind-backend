<?php
namespace App\Service\Appearance;

use App\Enum\Appearance\AppearanceRole;
use App\Enum\Appearance\Face;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\KitColor;
use App\Enum\Appearance\KitPart;
use App\Enum\Appearance\KitStyle;
use App\Enum\Appearance\LipColor;
use App\Enum\Appearance\Outfit;
use App\Enum\Appearance\SkinId;
use App\Enum\Appearance\WorldRegion;

/**
 * Generates the 15-key sprite Appearance config (see App\Form\Type\AppearanceType
 * for the full key list). Deterministic from (id, role, age) plus an optional
 * nationality, which biases `skin` towards the distribution of the
 * corresponding WorldRegion.
 *
 * All 15 keys are always present regardless of role — per the sprite's own
 * documented convention that a saved config may hold both player and staff
 * keys harmlessly (each type ignores the other's). Whichever group doesn't
 * apply to the role is filled with the sprite's DEFAULT_CONFIG values rather
 * than spending an RNG draw on something that won't render.
 *
 * Unlike the design this replaces, no cross-repo RNG-stream bit-parity
 * invariant is preserved here — no frontend generator exists yet for this
 * shape (only rendering code was provided), so the draw order below is a
 * fresh design, not pinned to any frontend counterpart.
 */
final class AppearanceGeneratorService
{
    private const DEFAULT_KIT      = 'stripes';
    private const DEFAULT_SHORTS   = 'black';
    private const DEFAULT_SOCKS    = 'primary';
    private const DEFAULT_OUTFIT   = 'coat';
    private const DEFAULT_TROUSERS = 'black';

    /** @return array<string, mixed> */
    public function generate(string $id, AppearanceRole $role, int $age, ?string $nationality = null): array
    {
        $rng      = new SeededRng(SeededRng::hashId($id));
        $isPlayer = $role === AppearanceRole::PLAYER;

        // Skin — weighted by world region when nationality is known, uniform otherwise.
        $region     = WorldRegion::fromNationality($nationality);
        $skinValues = array_map(static fn (SkinId $s) => $s->value, SkinId::cases());
        $skin       = $region === null
            ? $rng->pick($skinValues)
            : $rng->weightedPick($skinValues, array_values($region->skinWeights()));

        // Hair style — older entities skew toward shorter/balder styles.
        if ($age > 45) {
            $hairPool = ['crop', 'crop', 'buzz', 'bald', 'bald'];
        } elseif ($age > 35) {
            $hairPool = ['crop', 'buzz', 'quiff', 'bald'];
        } else {
            $hairPool = array_map(static fn (HairStyle $h) => $h->value, HairStyle::cases());
        }
        $hair = $rng->pick($hairPool);

        // Hair color — older entities skew grey.
        $hairColor = ($age > 45 && $rng->chance(0.4))
            ? HairColor::GREY->value
            : $rng->pick(array_map(static fn (HairColor $c) => $c->value, HairColor::cases()));

        // Face — pure cosmetic pick, no morale/personality coupling.
        $face = $rng->pick(array_map(static fn (Face $f) => $f->value, Face::cases()));

        // Facial hair — players always none.
        $facial = 'none';
        if (!$isPlayer && $age >= 20 && !$rng->chance(0.40)) {
            $pool = $age > 45
                ? ['stubble', 'beard', 'beard']
                : ['stubble', 'stubble', 'beard'];
            $facial = $rng->pick($pool);
        }

        // Lip color — applies regardless of role.
        $lip = $rng->pick(array_map(static fn (LipColor $c) => $c->value, LipColor::cases()));

        // Kit colors — always two different values, matching the sprite builder's own randomiser.
        $kitColors = array_map(static fn (KitColor $c) => $c->value, KitColor::cases());
        $primary   = $rng->pick($kitColors);
        do {
            $secondary = $rng->pick($kitColors);
        } while ($secondary === $primary);

        // Headband — independent of role.
        $headband = $rng->chance(0.20);

        // Player-only fields.
        if ($isPlayer) {
            $kitPartValues = array_map(static fn (KitPart $p) => $p->value, KitPart::cases());
            $kit    = $rng->pick(array_map(static fn (KitStyle $s) => $s->value, KitStyle::cases()));
            $shorts = $rng->pick($kitPartValues);
            $socks  = $rng->pick($kitPartValues);
        } else {
            $kit    = self::DEFAULT_KIT;
            $shorts = self::DEFAULT_SHORTS;
            $socks  = self::DEFAULT_SOCKS;
        }

        // Staff-only fields (also used for Scout/Agent, which render as the staff shape).
        if (!$isPlayer) {
            $outfit   = $rng->pick(array_map(static fn (Outfit $o) => $o->value, Outfit::cases()));
            $trousers = $rng->pick(array_map(static fn (KitPart $p) => $p->value, KitPart::cases()));
            $glasses  = $rng->chance(0.30);
        } else {
            $outfit   = self::DEFAULT_OUTFIT;
            $trousers = self::DEFAULT_TROUSERS;
            $glasses  = false;
        }

        return [
            'hair'      => $hair,
            'hairColor' => $hairColor,
            'headband'  => $headband,
            'skin'      => $skin,
            'face'      => $face,
            'facial'    => $facial,
            'lip'       => $lip,
            'primary'   => $primary,
            'secondary' => $secondary,
            'kit'       => $kit,
            'shorts'    => $shorts,
            'socks'     => $socks,
            'outfit'    => $outfit,
            'trousers'  => $trousers,
            'glasses'   => $glasses,
        ];
    }
}
