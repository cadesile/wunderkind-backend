<?php
namespace App\Service\Appearance;

use App\Enum\Appearance\AppearanceRole;
use App\Enum\Appearance\WorldRegion;

/**
 * Faithful PHP port of generateAppearance() from
 * wunderkind-app/src/engine/appearance.ts. Deterministic from (id, role, age)
 * plus an optional nationality, which biases skin tone towards the distribution
 * of the corresponding WorldRegion. Emits the 10 rendered fields only (dead
 * fields expression/earSize/eyebrowStyle are omitted). faceShape/eyeShape are
 * emitted as their frontend defaults.
 */
final class AppearanceGeneratorService
{
    private const SKIN_TONES = ['#f5dcc8', '#e8c49a', '#dfaa80', '#c47d4a', '#8b4c1e', '#5c2d0a'];
    private const HAIR_COLORS = ['blonde', 'light_brown', 'brown', 'dark_brown', 'black'];
    private const PLAYER_TRIMS = ['#f5c842', '#e8852a', '#3a8fd4', '#d94040', '#2eab5a', '#9b59b6'];
    private const STAFF_TRIMS = ['#4a5568', '#2d3748', '#374151', '#1e3a5f'];

    /** @return array<string, mixed> */
    public function generate(string $id, AppearanceRole $role, int $age, ?string $nationality = null): array
    {
        $rng = new SeededRng(SeededRng::hashId($id));

        // Skin tone — weighted by world region when the nationality is known,
        // uniform otherwise. Both paths consume exactly one RNG draw, so every
        // field below is unaffected by whether a nationality was supplied.
        $region   = WorldRegion::fromNationality($nationality);
        $skinTone = $region === null
            ? $rng->pick(self::SKIN_TONES)
            : $rng->weightedPick(self::SKIN_TONES, array_values($region->skinToneWeights()));

        // Hair style — older staff skews smart/bald
        if ($age > 45) {
            $hairStylePool = ['smart', 'smart', 'classic', 'bald', 'bald'];
        } elseif ($age > 35) {
            $hairStylePool = ['smart', 'classic', 'usual', 'round', 'bald'];
        } else {
            $hairStylePool = ['classic', 'messy', 'spike', 'usual', 'smart', 'round', 'bald'];
        }
        $hairStyle = $rng->pick($hairStylePool);

        // Hair color — older coaches/scouts skew darker
        if ($hairStyle === 'bald') {
            $hairColor = 'brown'; // irrelevant; won't render
        } elseif ($role === AppearanceRole::COACH && $age > 42 && $rng->chance(0.5)) {
            $hairColor = 'dark_brown';
        } elseif ($age > 38 && $rng->chance(0.3)) {
            $hairColor = 'dark_brown';
        } else {
            $hairColor = $rng->pick(self::HAIR_COLORS);
        }

        // NOTE: the JS reads one rng value for `expression` here (dead field). We
        // consume it too so downstream picks keep the same stream position.
        $rng->pick([0, 0, 1, 2]);

        // Role-specific accessory
        $accessory = null;
        if ($role === AppearanceRole::COACH) {
            if ($age > 40 && $rng->chance(0.38)) {
                $accessory = 'glasses';
            } elseif ($rng->chance(0.12)) {
                $accessory = 'beanie';
            } elseif ($rng->chance(0.08)) {
                $accessory = 'sunglasses';
            } elseif ($rng->chance(0.22)) {
                $accessory = 'whistle';
            }
        } elseif ($role === AppearanceRole::SCOUT) {
            $roll = $rng->next();
            if ($roll < 0.25) { $accessory = 'headset'; }
            elseif ($roll < 0.45) { $accessory = 'glasses'; }
        } elseif ($role === AppearanceRole::AGENT) {
            if ($rng->chance(0.30)) { $accessory = 'glasses'; }
        } elseif ($role === AppearanceRole::PLAYER && $age >= 20) {
            $roll = $rng->next();
            if ($roll < 0.06) { $accessory = 'face_tattoo'; }
            elseif ($roll < 0.12) { $accessory = 'neck_tattoo'; }
        }

        // Kit trim
        $kitTrim = $role === AppearanceRole::PLAYER
            ? $rng->pick(self::PLAYER_TRIMS)
            : $rng->pick(self::STAFF_TRIMS);

        // Facial hair — players always none
        $facialHair = 'none';
        if ($role !== AppearanceRole::PLAYER && $age >= 20) {
            if (!$rng->chance(0.40)) {
                $pool = $age > 45
                    ? ['stubble', 'stubble', 'beard', 'beard', 'moustache']
                    : ['stubble', 'stubble', 'moustache', 'goatee', 'beard', 'fench_2', 'french_smile'];
                $facialHair = $rng->pick($pool);
            }
        }

        // Nose and jersey
        $noseType      = $rng->pick(['normal', 'normal', 'small']);
        $jerseyVariant = (int) floor($rng->next() * 3) + 1;

        return [
            'skinTone'      => $skinTone,
            'hairStyle'     => $hairStyle,
            'hairColor'     => $hairColor,
            'accessory'     => $accessory,
            'kitTrim'       => $kitTrim,
            'facialHair'    => $facialHair,
            'faceShape'     => 'oval',
            'eyeShape'      => 'narrow',
            'noseType'      => $noseType,
            'jerseyVariant' => $jerseyVariant,
        ];
    }
}
