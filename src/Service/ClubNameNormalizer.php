<?php

namespace App\Service;

/**
 * Canonical form used to compare club names for collisions.
 *
 * The user composes their club name from the same place/suffix pools the NPC
 * generator draws from (see NpcClubGenerationService), so "Brescia AS" is a
 * name both sides can legitimately produce. Comparing on the raw string would
 * let trivial variations ("brescia as", "Brescia  AS", "Bréscia AS") slip past
 * the guard and still render as an identical name in-game.
 *
 * The client mirrors this exactly in src/utils/clubName.ts — keep the two in
 * step if the rules ever change.
 */
final class ClubNameNormalizer
{
    /**
     * Lowercase, strip diacritics, collapse whitespace.
     */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');

        $name = self::stripDiacritics($name);

        return (string) preg_replace('/\s+/u', ' ', $name);
    }

    /**
     * "bréscia" -> "brescia". Uses intl when available and falls back to iconv,
     * so the guard still works on a build without ext-intl.
     */
    private static function stripDiacritics(string $name): string
    {
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($transliterator !== null) {
                return $transliterator->transliterate($name) ?: $name;
            }
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        // iconv renders some characters as "'e" / "~n"; drop the combining marks
        // it leaves behind so "bréscia" and "brescia" still compare equal.
        return $ascii === false ? $name : (string) preg_replace('/[`\'"^~]/', '', $ascii);
    }

    /**
     * @param iterable<string> $existing
     */
    public static function isTaken(string $name, iterable $existing): bool
    {
        $needle = self::normalize($name);
        if ($needle === '') {
            return false;
        }

        foreach ($existing as $candidate) {
            if (self::normalize($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }
}
