<?php

declare(strict_types=1);

namespace App\Service\CountryContent;

use App\Enum\Country;
use App\Repository\Location\CountryProfileRepository;

/**
 * A country's club-naming content: the operator's override if there is one, the PHP const
 * otherwise.
 *
 * WHY THIS EXISTS. CountryContentRegistry is 2,364 lines of hardcoded arrays, and the admin's
 * CONTENT readiness check reported a country as "missing from CountryContentRegistry" — which
 * is to say, blocked until someone ships a deploy. Forty-six of the seventy-one Country cases
 * are in that state, so it is the normal condition of a new country rather than an edge case.
 *
 * THE REGISTRY IS NOT REPLACED, AND NOTHING IS REMOVED FROM IT. Its consts stay exactly where
 * they are and become the documented fallback, so the twenty-five authored countries behave
 * identically and no data has to be migrated. Its statics stay static too — worldRegion() and
 * capitalAliases() are called from enum methods with no container (NationalityGroup,
 * Appearance\WorldRegion), and threading a service into an enum to reach them would be a
 * much larger change than this feature needs.
 *
 * ONLY THE THREE NAMING CATEGORIES ARE RESOLVED HERE, because they are exactly the three the
 * readiness check can report. `places` is not one of them: it moved to the `location` table.
 *
 * NO MEMOISATION, deliberately. findByCode() is a find() on the primary key, so Doctrine's
 * identity map serves it after the first call — which is what a three-hundred-club generation
 * run needs — and a second cache on top could only go stale against the admin's own save.
 */
final class CountryNamingContentProvider
{
    /** The three categories this resolves. `places` is not one: it lives in the location table. */
    public const CATEGORIES = ['prestigeSuffixes', 'genericSuffixes', 'stadiumFormats'];

    public const SOURCE_OVERRIDE = 'override';
    public const SOURCE_REGISTRY = 'registry';
    public const SOURCE_NONE     = 'none';

    public function __construct(private readonly CountryProfileRepository $profiles) {}

    /** @return list<string> */
    public function prestigeSuffixes(Country $country): array
    {
        return $this->resolve($country, 'prestigeSuffixes');
    }

    /** @return list<string> */
    public function genericSuffixes(Country $country): array
    {
        return $this->resolve($country, 'genericSuffixes');
    }

    /** @return list<string> */
    public function stadiumFormats(Country $country): array
    {
        return $this->resolve($country, 'stadiumFormats');
    }

    /**
     * Which of the three a country has nothing for, from either source.
     *
     * NEVER RETURNS 'places', unlike CountryContentRegistry::missingCategories(). That one
     * answers about the registry and keeps doing so — it is what the registry's own tests
     * assert against. This one answers about the SYSTEM, which is the question the readiness
     * check is actually asking.
     *
     * @return list<string>
     */
    public function missingCategories(Country $country): array
    {
        return array_values(array_filter(
            self::CATEGORIES,
            fn (string $category): bool => $this->resolve($country, $category) === [],
        ));
    }

    /**
     * Each category's effective values and where they came from, for the admin and the CLI check.
     *
     * @return array<string, array{values: list<string>, source: string}>
     */
    public function describe(Country $country): array
    {
        $out = [];

        foreach (self::CATEGORIES as $category) {
            $override = $this->override($country, $category);
            $values   = $this->resolve($country, $category);

            $out[$category] = [
                'values' => $values,
                'source' => match (true) {
                    $override !== null => self::SOURCE_OVERRIDE,
                    $values !== []     => self::SOURCE_REGISTRY,
                    default            => self::SOURCE_NONE,
                },
            ];
        }

        return $out;
    }

    /** Whether this country's values for a category are the operator's rather than the registry's. */
    public function hasOverride(Country $country, string $category): bool
    {
        return $this->override($country, $category) !== null;
    }

    /** @return list<string> */
    private function resolve(Country $country, string $category): array
    {
        return $this->override($country, $category) ?? $this->fromRegistry($country, $category);
    }

    /** @return list<string>|null */
    private function override(Country $country, string $category): ?array
    {
        $profile = $this->profiles->findByCode($country->value);

        if ($profile === null) {
            return null;
        }

        $stored = match ($category) {
            'prestigeSuffixes' => $profile->getPrestigeSuffixes(),
            'genericSuffixes'  => $profile->getGenericSuffixes(),
            'stadiumFormats'   => $profile->getStadiumFormats(),
            default            => throw new \InvalidArgumentException(sprintf('Unknown naming category %s.', $category)),
        };

        // The setters already coerce [] to null, so this is a backstop against a row written
        // by hand or by an older build — not a second opinion about what empty means.
        return ($stored === null || $stored === []) ? null : array_values($stored);
    }

    /** @return list<string> */
    private function fromRegistry(Country $country, string $category): array
    {
        return match ($category) {
            'prestigeSuffixes' => CountryContentRegistry::prestigeSuffixes($country),
            'genericSuffixes'  => CountryContentRegistry::genericSuffixes($country),
            'stadiumFormats'   => CountryContentRegistry::stadiumFormats($country),
            default            => throw new \InvalidArgumentException(sprintf('Unknown naming category %s.', $category)),
        };
    }
}
