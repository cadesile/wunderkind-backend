<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Country;
use App\Service\CountryContent\NamePoolProvider;

class NameGeneratorService
{
    public function __construct(private readonly NamePoolProvider $pools) {}

    /**
     * Every demonym is derived from Country::nationalityMap() — there is no
     * standalone addendum any more. 'Polish' used to be one, because it had a
     * name pool below but no Country case; PL is now a real case, so keeping it
     * here as well would emit it twice.
     *
     * @return string[]
     */
    public static function nationalities(): array
    {
        return array_values(Country::nationalityMap());
    }

    /**
     * Whether a real first/last name pool exists for this nationality (vs. the 'English' fallback).
     *
     * NO LONGER STATIC. A pool can now be authored in the database, so answering this needs
     * the provider. nationalities() and formatFullName() stay static because they are pure
     * enum data and a pure string rule — nothing about either depends on what is stored.
     */
    public function hasNamePool(string $nationality): bool
    {
        return $this->pools->has($nationality);
    }

    /**
     * Every demonym => whether it can name somebody, in one query.
     *
     * For callers that need the whole picture — the admin pickers — so they do not ask
     * per demonym in a loop.
     *
     * @return array<string, bool>
     */
    public function hasNamePoolMap(): array
    {
        return $this->pools->hasMap();
    }

    /**
     * The nationality a pool will actually be drawn for.
     *
     * ONE COPY OF THE FALLBACK RULE. There used to be five, which is precisely how
     * generateName() came to throw while the other four fell back to English — a divergence
     * nobody noticed until `app:pool:warm` across all countries started exiting non-zero for
     * the thirty-nine countries with no pool.
     */
    private function resolveNationality(string $nationality): string
    {
        return $this->pools->has($nationality) ? $nationality : 'English';
    }

    /**
     * Nationalities written surname-first, even in English-language football coverage.
     *
     * Korean is the clear case: Son Heung-min, Kim Min-jae, Hwang Hee-chan — given-first
     * ("Heung-min Son") reads as a mistake to anyone who follows the game. Japanese is
     * deliberately NOT here despite the same native convention, because football writes
     * Japanese players given-first (Takumi Minamino, Daichi Kamada). Chinese is the one
     * other candidate (Wu Lei is surname-first in coverage) and is left out only because
     * nobody has asked for it — add the key, nothing else.
     */
    private const SURNAME_FIRST_NATIONALITIES = ['South Korean'];

    /**
     * Joins a name's parts in the order that nationality actually writes them.
     *
     * The one place that knows about name order, so the rule cannot be applied in one
     * caller and forgotten in the next. Tolerates an empty surname, which generatePlayerName()
     * produces for Brazilian mononyms.
     */
    public static function formatFullName(string $firstName, string $lastName, string $nationality): string
    {
        $lastName = trim($lastName);

        if ($lastName === '') {
            return trim($firstName);
        }

        return in_array($nationality, self::SURNAME_FIRST_NATIONALITIES, true)
            ? trim("{$lastName} {$firstName}")
            : trim("{$firstName} {$lastName}");
    }

    /**
     * Generate a simple full-name string (used for scouts, agents, coaches).
     * Brazilian players have a 20% chance of a mononym.
     *
     * IT USED TO THROW on a nationality with no pool, alone among the four generators. That
     * inconsistency was a live bug rather than a style point: `app:pool:warm` across all
     * countries generated five hundred players per country happily — generatePlayerName()
     * has always fallen back — and then died here on the first member of staff, for each of
     * the thirty-nine countries with no pool, and exited non-zero on a run that had largely
     * worked.
     *
     * The loud failure is not lost, it is moved somewhere it can be acted on: WarmPoolCommand
     * says once per country that names will be English, where the throw said it once per
     * person and only to a stack trace.
     */
    public function generateName(string $nationality): string
    {
        $nationality = $this->resolveNationality($nationality);

        $firstNames = $this->pools->firstNames($nationality);
        $firstName  = $firstNames[array_rand($firstNames)];

        if ($nationality === 'Brazilian' && random_int(1, 5) === 1) {
            return $firstName;
        }

        $lastNames = $this->pools->lastNames($nationality);
        $lastName  = $lastNames[array_rand($lastNames)];

        return self::formatFullName($firstName, $lastName, $nationality);
    }

    /**
     * Generate a player name with cultural complexity, returned as separate parts.
     *
     * Rules applied in order:
     *  - Brazilian: 20% mononym (lastName = '')
     *  - Spanish / Portuguese / Brazilian / Argentine: 15% double-surname
     *  - All: 5% generational suffix appended to lastName
     *
     * @return array{firstName: string, lastName: string}
     */
    public function generatePlayerName(string $nationality): array
    {
        $nationality = $this->resolveNationality($nationality);

        $firstNames = $this->pools->firstNames($nationality);
        $lastNames  = $this->pools->lastNames($nationality);
        $firstName  = $firstNames[array_rand($firstNames)];

        // Brazilian mononym (20%)
        if ($nationality === 'Brazilian' && random_int(1, 5) === 1) {
            return ['firstName' => $firstName, 'lastName' => ''];
        }

        $lastName = $lastNames[array_rand($lastNames)];

        // Double-surname for Iberian / Latin cultures (15%)
        $doubleSurnameNats = ['Spanish', 'Portuguese', 'Brazilian', 'Argentine'];
        if (in_array($nationality, $doubleSurnameNats, true) && random_int(1, 100) <= 15) {
            $second = $lastNames[array_rand($lastNames)];
            // Avoid identical pair
            $attempts = 0;
            while ($second === $lastName && $attempts < 5) {
                $second = $lastNames[array_rand($lastNames)];
                $attempts++;
            }
            $lastName = "{$lastName} {$second}";
        }

        // Generational suffix (5%)
        if (random_int(1, 100) <= 5) {
            $suffixes = match ($nationality) {
                'Brazilian', 'Portuguese' => ['Jr.', 'Filho', 'Neto'],
                'Spanish', 'Argentine'    => ['Jr.', 'II', 'III'],
                default                   => ['Jr.', 'II', 'III'],
            };
            $lastName .= ' ' . $suffixes[array_rand($suffixes)];
        }

        return ['firstName' => $firstName, 'lastName' => $lastName];
    }

    /**
     * Return a single first name for a given nationality (used for guardian generation).
     */
    public function generateFirstName(string $nationality): string
    {
        $firstNames = $this->pools->firstNames($this->resolveNationality($nationality));

        return $firstNames[array_rand($firstNames)];
    }

    /**
     * Return a single last name for a given nationality.
     */
    public function generateLastName(string $nationality): string
    {
        $lastNames = $this->pools->lastNames($this->resolveNationality($nationality));

        return $lastNames[array_rand($lastNames)];
    }

    /**
     * A random nationality that can actually produce a name.
     *
     * Filtered by hasNamePool() rather than picking from nationalities() directly, because
     * that method derives every demonym from Country and a Country case is allowed to exist
     * without a name pool — the enum carries nations that are only ever an owner's
     * nationality, never a generation target. Picking one of those and handing it to
     * generateName() throws "Unknown nationality", which surfaced as world-pack, tier-pack
     * and spoof-entrant generation all failing the moment the UEFA nations were added.
     *
     * The same latent hole existed before that, with a single case (South Korean had a
     * Country case and no pool), so this is not specific to one batch of countries: it is
     * the general rule that selection must come from pools, not from the enum.
     */
    public function getRandomNationality(): string
    {
        // ONE memoised list, not a filter over all seventy-one demonyms. Asking per demonym
        // here is seventy-one pool reads per player — about three and a half million on a
        // full warm — and is the single easiest way to make this quadratic.
        $nationalities = $this->pools->pooledNationalities();

        return $nationalities[array_rand($nationalities)];
    }
}
