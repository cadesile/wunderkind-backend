<?php

declare(strict_types=1);

namespace App\Service\CountryContent;

use App\Enum\Country;
use App\Repository\Location\CountryProfileRepository;

/**
 * A demonym's person-name pool: the operator's override if there is one, the shipped list
 * otherwise.
 *
 * Structurally this is CountryNamingContentProvider one level along — same override-else-
 * registry resolution, same tri-state, same "read through here, never the columns directly".
 *
 * IT DIFFERS IN EXACTLY ONE WAY, AND THAT WAY IS NOT OPTIONAL: IT MEMOISES.
 *
 * CountryNamingContentProvider deliberately does not, reasoning that findByCode() is a find()
 * on the primary key so Doctrine's identity map serves the repeats. That reasoning holds for
 * rendering a form. It is false here, twice over:
 *
 *   1. VOLUME. These are read on the hottest loop in the application. Generating 500 players
 *      is ~1,400 pool reads; `app:pool:warm` across all countries is ~116,000. The old PHP
 *      constant answered each in ~24 nanoseconds, so a naive port swaps a refcount bump for a
 *      Postgres round trip 116,000 times — twenty-three seconds of pure latency on localhost,
 *      minutes over a network, before hydration.
 *   2. THE IDENTITY MAP IS NOT THERE. MarketPoolService::generatePlayers() calls
 *      $em->clear(Player::class) every fifty players — and ORM 3's clear() is declared
 *      `clear(): void`, so PHP discards that argument and it is a FULL clear. A 500-player run
 *      empties the map ten times; a full warm empties it about a thousand times. Nothing is
 *      cached behind this class.
 *
 * WHAT IS CACHED IS PLAIN STRINGS, never a CountryProfile. An entity in the memo would be
 * detached by the next clear() and read as stale state. Scalars are immune.
 *
 * MISSES ARE CACHED TOO, and that is load-bearing rather than tidy: thirty-nine of the
 * seventy-one countries have no pool from either source, and getRandomNationality() asks about
 * every demonym once per player. Without negative caching those thirty-nine re-query forever.
 *
 * KEYED ON THE DEMONYM, not the country code. That is what every caller holds and what
 * player.nationality stores, and it means Country::fromNationality() — a linear scan of
 * seventy-one cases with a strtolower each — is paid once per demonym rather than ~8 million
 * times per warm.
 *
 * THE MEMO LIVES ONE PROCESS, AND THE STALENESS IS INTENDED. `app:pool:warm` runs for tens of
 * minutes; a pool saved at minute twenty is deliberately not picked up, because a run whose
 * first thirty countries used the old pool and whose last forty-one used the new one is a
 * worse artefact than one that is uniformly a minute stale. Over HTTP each request is a fresh
 * container, so a save lands on the next chunk. forget() exists for the one case that needs
 * better: the admin handler that saves and re-renders in the same request.
 *
 * An instance field rather than a static one: the container hands every consumer the same
 * instance, so the hit rate is identical, without a cache that survives between tests in one
 * PHPUnit process and poisons whichever runs next.
 */
final class NamePoolProvider
{
    public const SOURCE_OVERRIDE = 'override';
    public const SOURCE_REGISTRY = 'registry';
    public const SOURCE_NONE     = 'none';

    /** @var array<string, array{firstNames: list<string>, lastNames: list<string>, source: string}> */
    private array $resolved = [];

    /** @var list<string>|null */
    private ?array $pooled = null;

    /** Whether the whole override set has been loaded, so a miss is known rather than unasked. */
    private bool $loadedAll = false;

    public function __construct(private readonly CountryProfileRepository $profiles) {}

    /** @return list<string> */
    public function firstNames(string $nationality): array
    {
        return $this->resolve($nationality)['firstNames'];
    }

    /** @return list<string> */
    public function lastNames(string $nationality): array
    {
        return $this->resolve($nationality)['lastNames'];
    }

    /**
     * Whether this demonym can name somebody at all, from either source.
     *
     * BOTH LISTS MUST BE NON-EMPTY. A pool with surnames and no first names cannot produce a
     * name, and callers treat this as "generateName() will work".
     */
    public function has(string $nationality): bool
    {
        $pool = $this->resolve($nationality);

        return $pool['firstNames'] !== [] && $pool['lastNames'] !== [];
    }

    /**
     * Every demonym that can name somebody. The candidate set getRandomNationality() draws from.
     *
     * ONE QUERY FOR THE WHOLE SET, computed once per process. Asking has() per demonym in a
     * loop would be seventy-one calls per player — about three and a half million on a full
     * warm — which is the single easiest way to make this feature quadratic.
     *
     * @return list<string>
     */
    public function pooledNationalities(): array
    {
        if ($this->pooled !== null) {
            return $this->pooled;
        }

        $this->loadAll();

        $pooled = [];

        foreach (Country::nationalityMap() as $nationality) {
            if ($this->has($nationality)) {
                $pooled[] = $nationality;
            }
        }

        return $this->pooled = $pooled;
    }

    /**
     * Every demonym => whether it is nameable, for the admin pickers.
     *
     * One query, not seventy-one. NationalityPoolCounts exists to avoid exactly that N+1.
     *
     * @return array<string, bool>
     */
    public function hasMap(): array
    {
        $this->loadAll();

        $out = [];

        foreach (Country::nationalityMap() as $nationality) {
            $out[$nationality] = $this->has($nationality);
        }

        return $out;
    }

    /** @return array{firstNames: list<string>, lastNames: list<string>, source: string} */
    public function describe(string $nationality): array
    {
        return $this->resolve($nationality);
    }

    public function hasOverride(string $nationality): bool
    {
        return $this->resolve($nationality)['source'] === self::SOURCE_OVERRIDE;
    }

    /**
     * Drop what is memoised, so the next read goes back to the database.
     *
     * For the write path only: an admin handler that saves a pool and then re-renders the form
     * in the same request. Nothing on a generation path should call this.
     */
    public function forget(?string $nationality = null): void
    {
        if ($nationality === null) {
            $this->resolved  = [];
            $this->loadedAll = false;
        } else {
            unset($this->resolved[$nationality]);
        }

        $this->pooled = null;
    }

    /** @return array{firstNames: list<string>, lastNames: list<string>, source: string} */
    private function resolve(string $nationality): array
    {
        if (isset($this->resolved[$nationality])) {
            return $this->resolved[$nationality];
        }

        $override = $this->loadedAll ? null : $this->overrideFor($nationality);

        $first = $override['firstNames'] ?? null;
        $last  = $override['lastNames'] ?? null;

        // The override REPLACES per list, it does not merge: a union would mean an operator
        // could never remove a bad shipped name. Each list falls back independently, so
        // overriding surnames alone leaves first names on the const.
        $resolved = [
            'firstNames' => $first ?? NamePoolRegistry::firstNames($nationality),
            'lastNames'  => $last ?? NamePoolRegistry::lastNames($nationality),
            'source'     => match (true) {
                $first !== null || $last !== null              => self::SOURCE_OVERRIDE,
                NamePoolRegistry::has($nationality)            => self::SOURCE_REGISTRY,
                default                                        => self::SOURCE_NONE,
            },
        ];

        return $this->resolved[$nationality] = $resolved;
    }

    /** @return array{firstNames: ?list<string>, lastNames: ?list<string>}|null */
    private function overrideFor(string $nationality): ?array
    {
        $country = Country::fromNationality($nationality);

        if ($country === null) {
            // A demonym no Country case carries — spoof payloads and callers can pass
            // anything. Fall through to the registry rather than throwing.
            return null;
        }

        $profile = $this->profiles->findByCode($country->value);

        if ($profile === null) {
            return null;
        }

        return ['firstNames' => $profile->getFirstNames(), 'lastNames' => $profile->getLastNames()];
    }

    /**
     * Fill the memo for every country from one query.
     *
     * Called by the two whole-set readers. After this, resolve() answers from the memo and
     * never touches the repository again — which is what makes a 116,000-read warm cost
     * one query rather than 116,000.
     */
    private function loadAll(): void
    {
        if ($this->loadedAll) {
            return;
        }

        $overrides = $this->profiles->namePoolOverrides();
        $this->loadedAll = true;

        foreach (Country::nationalityMap() as $code => $nationality) {
            if (isset($this->resolved[$nationality])) {
                continue;
            }

            $first = $overrides[$code]['firstNames'] ?? null;
            $last  = $overrides[$code]['lastNames'] ?? null;

            $this->resolved[$nationality] = [
                'firstNames' => $first ?? NamePoolRegistry::firstNames($nationality),
                'lastNames'  => $last ?? NamePoolRegistry::lastNames($nationality),
                'source'     => match (true) {
                    $first !== null || $last !== null   => self::SOURCE_OVERRIDE,
                    NamePoolRegistry::has($nationality) => self::SOURCE_REGISTRY,
                    default                             => self::SOURCE_NONE,
                },
            ];
        }
    }
}
