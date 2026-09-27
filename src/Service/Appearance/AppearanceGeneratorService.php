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

        // Hair style — weighted by age band; mohawk/afro/cornrows stay rare at every age.
        $hairWeights = $this->hairStyleWeights($age);
        $hair        = $rng->weightedPick(array_keys($hairWeights), array_values($hairWeights));

        // Hair color — older entities skew grey; ginger (orange) is rare at every age.
        $hairColorWeights = $this->hairColorWeights();
        $hairColor = ($age > 45 && $rng->chance(0.4))
            ? HairColor::GREY->value
            : $rng->weightedPick(array_keys($hairColorWeights), array_values($hairColorWeights));

        // Face — pure cosmetic pick, no morale/personality coupling. Players
        // always default to neutral; only staff/scout/agent get a varied expression.
        $face = $isPlayer
            ? Face::NEUTRAL->value
            : $rng->pick(array_map(static fn (Face $f) => $f->value, Face::cases()));

        // Facial hair — age-gated by role; players skew later/rarer than staff.
        $facial = $this->rollFacialHair($rng, $age, $isPlayer);

        // Lip color — dark skin tones always get the reserved deep-brown lip;
        // everyone else picks uniformly from the rest of the palette.
        $isDarkSkin = in_array($skin, [SkinId::S5->value, SkinId::S6->value], true);
        if ($isDarkSkin) {
            $lip = LipColor::DEEP_BROWN->value;
        } else {
            $lightLipColors = array_map(
                static fn (LipColor $c) => $c->value,
                array_filter(LipColor::cases(), static fn (LipColor $c) => $c !== LipColor::DEEP_BROWN),
            );
            $lip = $rng->pick(array_values($lightLipColors));
        }

        // Kit colors — always two different values, matching the sprite builder's own randomiser.
        $kitColors = array_map(static fn (KitColor $c) => $c->value, KitColor::cases());
        $primary   = $rng->pick($kitColors);
        do {
            $secondary = $rng->pick($kitColors);
        } while ($secondary === $primary);

        // Headband — vanishingly rare for players (1-in-1000); staff/scout/agent keep the base rate.
        $headband = $rng->chance($isPlayer ? 0.001 : 0.20);

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

    /**
     * Percentage weights keyed by HairStyle value, in HairStyle enum order,
     * banded by age. Mohawk/afro/cornrows stay low at every age (mirrors the
     * demographic-weighting pattern in WorldRegion::skinWeights()) and taper
     * further as age rises, in favour of practical short styles.
     *
     * @return array<string, int>
     */
    public function hairStyleWeights(int $age): array
    {
        // [BALD, BUZZ, CROP, QUIFF, MOHAWK, AFRO, LONG, BUN, CORNROWS]
        $weights = match (true) {
            $age > 45 => [30, 28, 22,  8,  1,  3,  3,  3,  2],
            $age > 35 => [14, 22, 24, 12,  2,  5,  8,  8,  5],
            default   => [ 8, 18, 20, 16,  4,  7, 14,  8,  5],
        };

        return array_combine(
            array_map(static fn (HairStyle $h) => $h->value, HairStyle::cases()),
            $weights,
        );
    }

    /**
     * Percentage weights keyed by HairColor value, in HairColor enum order.
     * Ginger (orange) is deliberately rare; the rest share the remainder
     * roughly evenly. Independent of age — the separate age>45 grey-forcing
     * chance in `generate()` runs first and short-circuits this entirely.
     *
     * @return array<string, int>
     */
    public function hairColorWeights(): array
    {
        // [BLACK, DARK_BROWN, BROWN, GINGER, BLONDE, GREY]
        $weights = [20, 20, 20, 5, 20, 15];

        return array_combine(
            array_map(static fn (HairColor $c) => $c->value, HairColor::cases()),
            $weights,
        );
    }

    /**
     * Facial hair, age-gated by role. Players are newly eligible (they were
     * previously hardcoded to 'none') but at a higher minimum age and a lower
     * overall chance than staff/scout/agent, reflecting that it's an
     * occasional look rather than the norm.
     */
    private function rollFacialHair(SeededRng $rng, int $age, bool $isPlayer): string
    {
        $minAge = $isPlayer ? 25 : 20;
        if ($age < $minAge) {
            return 'none';
        }

        $growsFacialHair = $isPlayer ? 0.35 : 0.60;
        if (!$rng->chance($growsFacialHair)) {
            return 'none';
        }

        $pool = $age > 45
            ? ['stubble', 'beard', 'beard']
            : ['stubble', 'stubble', 'beard'];
        return $rng->pick($pool);
    }
}
