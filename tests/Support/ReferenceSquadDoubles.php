<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\PoolConfig;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\PoolConfigRepository;
use App\Service\CountryContent\NamePoolProvider;
use App\Service\NameGeneratorService;
use App\Service\PlayerGenerationService;

/**
 * The two collaborators `EconomyScaleResolver` grew on 2026-11-19, as objects a plain
 * `TestCase` can build.
 *
 * WHY THIS EXISTS. The resolver's reference squad is now built from the ability the GENERATOR
 * would actually produce — see `EconomyScaleResolver::referenceSquadAbilitySum()` — so it
 * depends on `PlayerGenerationService` and on the pool config that carries the first-team age
 * range and the position weights. Fifteen unit tests construct the resolver directly, and
 * every one of them is a test of the arithmetic rather than of the generator, so they all want
 * the same thing: the SHIPPED pool defaults and a real generator. Stubbing
 * `PlayerGenerationService` instead would be worse than useless here — a stub returns
 * whatever it is told, and what these tests are pinning is precisely the composition of the
 * real chain.
 *
 * These are real objects rather than PHPUnit stubs because the repositories are Doctrine
 * `ServiceEntityRepository` subclasses: a stub of one is fine, but it needs a `TestCase`
 * instance to create, which a static factory does not have and a shared trait would force on
 * every caller. Neither anonymous class calls its parent constructor, which is what lets them
 * exist without a `ManagerRegistry`; both override every method the resolver reaches.
 *
 * `NamePoolProvider` is wired to an equally inert profile repository because
 * `expectedCurrentAbility()` never generates a name — it never builds a Player at all. If a
 * future caller makes it do so, this double will return nothing rather than lie about it.
 */
final class ReferenceSquadDoubles
{
    /**
     * ⚠️ `playerAgeMax` IS RAISED TO THE DEPLOYED VALUE, AND IT HAS TO BE.
     *
     * `PoolConfig`'s entity default is **13** — the upper bound of the YOUTH pool, which is what
     * that column is mostly for. But `firstTeamAgeRange()` is
     * `[playerFirstTeamAgeMin, max(playerFirstTeamAgeMin, playerAgeMax)]`, so on the raw entity
     * defaults it collapses to **{19, 19}** — and 19 is the one age the youth ability ceiling
     * still binds on, at 20% of potential. A reference squad built from that is twenty-odd
     * youth-ceilinged nineteen-year-olds, every expected ability lands near a ninth of its
     * potential, and `S_tier` comes out about **58x** the figure the deployed pool produces.
     *
     * That is not a bug in the resolver — it is the one-source property working: a box whose
     * pool really is configured that way also GENERATES squads that way, because
     * `WorldPackClubGenerationService::generatePlayers()` reads the same range. But it makes
     * every figure a unit test pins unrepresentative of any real box, so these doubles carry the
     * deployed range (19-33 as of 2026-11-19) instead. A test that wants to pin the collapsed
     * case should pass its own `PoolConfig` and say so.
     */
    public static function poolConfigRepository(?PoolConfig $config = null): PoolConfigRepository
    {
        $pool = $config ?? (new PoolConfig())->setPlayerAgeMax(33);

        return new class ($pool) extends PoolConfigRepository {
            public function __construct(private readonly PoolConfig $pool) {}

            public function getConfig(bool $flush = false): PoolConfig
            {
                return $this->pool;
            }
        };
    }

    public static function playerGeneration(?PoolConfig $config = null): PlayerGenerationService
    {
        $profiles = new class extends CountryProfileRepository {
            public function __construct() {}
        };

        return new PlayerGenerationService(
            new NameGeneratorService(new NamePoolProvider($profiles)),
            self::poolConfigRepository($config),
        );
    }
}
