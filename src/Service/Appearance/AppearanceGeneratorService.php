<?php
namespace App\Service\Appearance;

use App\Enum\Appearance\AppearanceRole;
use App\Enum\Appearance\Face;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\Headwear;
use App\Enum\Appearance\Neckwear;
use App\Enum\Appearance\KitColor;
use App\Enum\Appearance\KitPart;
use App\Enum\Appearance\KitStyle;
use App\Enum\Appearance\LipColor;
use App\Enum\Appearance\Outfit;
use App\Enum\Appearance\SkinId;
use App\Enum\Appearance\Tattoo;
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
        //
        // WEIGHTED rather than uniform since the sprite's V4 drop. Six of the new
        // faces are the unhappy end of its morale ladder, and a uniform pick over
        // all fifteen would make roughly a fifth of every staff pool look
        // miserable or distraught for no reason — these are stored identity
        // sprites, not a live mood readout. Same reasoning as GINGER's 5% in
        // hairColorWeights().
        if ($isPlayer) {
            $face = Face::NEUTRAL->value;
        } else {
            $faceWeights = $this->faceWeights();
            $face        = $rng->weightedPick(array_keys($faceWeights), array_values($faceWeights));
        }

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
            // Weighted, not uniform: the four V4 additions are real staff looks but
            // the original four read as more "club official", so they stay the
            // majority rather than being diluted to an eighth each.
            $outfitWeights = $this->outfitWeights();
            $outfit        = $rng->weightedPick(array_keys($outfitWeights), array_values($outfitWeights));
            $trousers = $rng->pick(array_map(static fn (KitPart $p) => $p->value, KitPart::cases()));
            $glasses  = $rng->chance(0.30);
        } else {
            $outfit   = self::DEFAULT_OUTFIT;
            $trousers = self::DEFAULT_TROUSERS;
            $glasses  = false;
        }

        $extras = $this->rollExtras($rng, $age, $isPlayer, $outfit);

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
        ] + $extras;
    }

    /**
     * AvatarExtras — accessories and tattoos, stored FLAT beside the fifteen config
     * keys because that is the shape the sprite's own splitAvatarConfig() consumes.
     *
     * Role-gating is the sprite's job (it ignores an armband on staff and headwear on
     * a player), so this only rolls what the role can actually show, to keep stored
     * data honest rather than full of values that draw nothing.
     *
     * ── The age gate is the important part ──────────────────────────────────────
     *
     * The web builder's own rates (earring 25%, armband 10%, wristband 20%, snood 10%,
     * headwear 30%, neckwear 50%, each tattoo 15%) assume an adult. PoolConfig
     * generates players at 12-13 by default — this is a youth-academy game — and six
     * independent 15% rolls would ink roughly 62% of them. Tattooed twelve-year-olds
     * is not a look to ship, so tattoos and earrings are gated to 18+, the same shape
     * of rule rollFacialHair() already applies.
     *
     * Staff/scout/agent are adults, so they take the builder's rates as published.
     *
     * @return array<string, bool|string|list<string>>
     */
    private function rollExtras(SeededRng $rng, int $age, bool $isPlayer, string $outfit): array
    {
        $adult = $age >= 18;

        $extras = [
            'earring'   => $adult && $rng->chance(0.25),
            'armband'   => false,
            'wristband' => false,
            'snood'     => false,
            'headwear'  => Headwear::NONE->value,
            'neckwear'  => Neckwear::NONE->value,
            'tattoos'   => [],
        ];

        if ($isPlayer) {
            // Kit accessories are not age-gated: a wristband on a youth player is
            // ordinary, unlike ink.
            $extras['armband']   = $rng->chance(0.10);
            $extras['wristband'] = $rng->chance(0.20);
            $extras['snood']     = $rng->chance(0.10);
        } else {
            // array_values(), because array_filter() PRESERVES KEYS and pick() indexes
            // from 0 — filtering NONE out of a cases() list leaves a gap at index 0.
            $extras['headwear'] = $rng->chance(0.30)
                ? $rng->pick(array_values(array_map(
                    static fn (Headwear $h): string => $h->value,
                    array_filter(Headwear::cases(), static fn (Headwear $h): bool => $h !== Headwear::NONE),
                )))
                : Headwear::NONE->value;

            $extras['neckwear'] = $rng->chance(0.50)
                ? $rng->pick(array_values(array_map(
                    static fn (Neckwear $n): string => $n->value,
                    array_filter(Neckwear::cases(), static fn (Neckwear $n): bool => $n !== Neckwear::NONE),
                )))
                : Neckwear::NONE->value;
        }

        if ($adult) {
            // Only ink that this role and outfit actually leave on show — the same
            // rule the sprite draws by and the admin widget offers by.
            $visible = Tattoo::visibleFor($isPlayer, Outfit::tryFrom($outfit));

            foreach ($visible as $tattoo) {
                if ($rng->chance(0.15)) {
                    $extras['tattoos'][] = $tattoo->value;
                }
            }
        }

        return $extras;
    }

    /**
     * Percentage weights keyed by HairStyle value, in HairStyle enum order,
     * banded by age. Mohawk/afro/cornrows stay low at every age (mirrors the
     * demographic-weighting pattern in WorldRegion::skinWeights()) and taper
     * further as age rises, in favour of practical short styles.
     *
     * POSITIONAL, and combined with HairStyle::cases() — so this array and that
     * enum must stay the same length and the same order. A mismatch is an
     * array_combine() error rather than a silent mislabelling, which is why the
     * column header below is maintained by hand.
     *
     * The seven styles from CURTAINS on arrived with the sprite's V4 drop. They
     * follow the same shape as the existing tail: SIDEPART is the one that rises
     * with age (it is the conventional older look), while PONYTAIL, LOCS and BOB
     * stay rare everywhere and BOB drops to zero in the oldest band — the same
     * taper MOHAWK already had, for the same reason.
     *
     * @return array<string, int>
     */
    public function hairStyleWeights(int $age): array
    {
        //        BALD BUZZ CROP QUIF MOHA AFRO LONG  BUN CORN CURT SIDE FLAT SPIK PONY LOCS  BOB
        $weights = match (true) {
            $age > 45 => [  26,  24,  18,   6,   1,   2,   2,   2,   2,   3,   8,   3,   1,   1,   1,   0],
            $age > 35 => [  12,  18,  20,  10,   2,   4,   5,   5,   4,   5,   6,   3,   3,   1,   1,   1],
            default   => [   6,  14,  16,  13,   3,   6,   9,   6,   4,   7,   4,   3,   4,   2,   2,   1],
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
    /**
     * Percentage weights keyed by Face value, in Face enum order. Staff/scout/agent
     * only — players are always NEUTRAL.
     *
     * POSITIONAL against Face::cases(), like hairStyleWeights().
     *
     * The six faces from CONTENT on arrived with the sprite's V4 drop, and the
     * last three of them (GLUM, MISERABLE, DISTRAUGHT) are deliberately rare: a
     * stored identity sprite that looks distraught forever is a bug report, not
     * characterisation. They are kept non-zero rather than excluded so an admin
     * seeing one in the picker knows generation can produce it too.
     *
     * @return array<string, int>
     */
    public function faceWeights(): array
    {
        //         NEUT HAPP WINK FOCU SHOU  SAD FRUS ANGR COOL CONT CHEE ELAT GLUM MISE DIST
        $weights = [  22,  14,   7,  12,   4,   4,   4,   3,   8,  10,   6,   3,   1,   1,   1];

        return array_combine(
            array_map(static fn (Face $f) => $f->value, Face::cases()),
            $weights,
        );
    }

    /**
     * Percentage weights keyed by Outfit value, in Outfit enum order. Staff/scout/
     * agent only — a player's `outfit` is a fixed default.
     *
     * POSITIONAL against Outfit::cases(), like hairStyleWeights().
     *
     * @return array<string, int>
     */
    public function outfitWeights(): array
    {
        //         COAT TRAC SUIT JUMP POLO SHIR GILE RAIN
        $weights = [  16,  18,  16,  14,  12,  10,   8,   6];

        return array_combine(
            array_map(static fn (Outfit $o) => $o->value, Outfit::cases()),
            $weights,
        );
    }

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
