<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\League;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The starter squad is GENERATED, not drawn from the shared player pool.
 *
 * This file used to be a unit test over stubbed `PlayerRepository`/`StaffRepository`/
 * `ScoutRepository`, asserting the shape of the pool queries, that the drawn rows were
 * `em->remove()`d, and that an empty pool threw `InsufficientStarterPoolException`. None of
 * that exists any more: `generatePack()` builds the squad through
 * `WorldPackClubGenerationService`, the same generator every NPC club goes through, and
 * touches no pool table.
 *
 * So the tests are integration tests now, against the real container with the pool left
 * EMPTY — which is both simpler and a stronger statement than the stubs were, because an
 * empty pool used to be the one condition that made game init fail outright.
 *
 * The four domain properties the old file protected are all still protected here, because
 * each was a real regression once:
 *
 *   1. first-team ages, not children (the pool spans youth ages for `/api/players/youth`);
 *   2. the age floor comes from `PoolConfig`, not a constant;
 *   3. the band is the country's REAL bottom tier (assuming 8 put founders ~45 ability
 *      points below their opponents in any shallower pyramid);
 *   4. `DEFAULT_STARTER_TIER` only when the country has no pyramid at all.
 */
class StarterPackServiceTest extends KernelTestCase
{
    private const COUNTRY = 'QZ'; // no Country case, no fixtures, no leagues unless we add them

    private EntityManagerInterface $em;
    private StarterPackService $service;
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(StarterPackService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        $this->cleanup = [];

        parent::tearDown();
    }

    /**
     * The headline property, and the inverse of the test it replaced.
     *
     * An empty pool used to mean `InsufficientStarterPoolException` and a `412` at the first
     * step of game init, with no client-side escape. It now means nothing at all.
     */
    public function testAFullSquadIsGeneratedWithAnEmptyPool(): void
    {
        $poolCount = (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM player');

        $pack = $this->service->generatePack(self::COUNTRY, 8);

        $expected = self::getContainer()->get(StarterConfigRepository::class)->getConfig();

        $this->assertCount(
            $expected->getStarterPlayerCount(),
            $pack['players'],
            'the squad is the authored size exactly — generation has no shortfall to report',
        );
        $this->assertCount(
            $expected->getStarterManagerCount()
                + $expected->getStarterCoachCount()
                + $expected->getStarterChairmanCount()
                + $expected->getStarterDirectorOfFootballCount()
                + $expected->getStarterFacilityManagerCount(),
            $pack['staff'],
        );
        $this->assertCount($expected->getStarterScoutCount(), $pack['scouts']);

        // Nothing taken, nothing added: the pool is exactly as it was.
        $this->assertSame(
            $poolCount,
            (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM player'),
            'generatePack() must neither consume nor persist pool rows',
        );
    }

    /** Generated, so the ids cannot collide with a shared population — or with each other. */
    public function testTwoSquadsForOneCountryShareNoPlayers(): void
    {
        $a = $this->service->generatePack(self::COUNTRY, 8);
        $b = $this->service->generatePack(self::COUNTRY, 8);

        $idsA = array_column($a['players'], 'id');
        $idsB = array_column($b['players'], 'id');

        $this->assertNotEmpty($idsA);
        $this->assertSame([], array_intersect($idsA, $idsB));
    }

    /**
     * Regression: starter packs were handing new clubs 11 and 12 year olds.
     *
     * The pool deliberately spans youth ages so it can also supply `GET /api/players/youth`.
     * The draw that used to be here filtered on ability, position and nationality with no age
     * condition, so it took whatever the band matched. Generation has the same hazard from the
     * other direction — `PoolConfig`'s range is pool-wide — which is why
     * `WorldPackClubGenerationService` generates a squad inside `firstTeamAgeRange()`.
     *
     * Asserted as an age against the configured floor rather than "an argument was passed":
     * an off-by-one here is invisible in the response and surfaces only as a 17 year old.
     */
    public function testEveryGeneratedPlayerClearsTheFirstTeamAgeFloor(): void
    {
        $poolConfig = self::getContainer()->get(PoolConfigRepository::class)->getConfig();
        $floor      = $poolConfig->getPlayerFirstTeamAgeMin();
        $today      = new \DateTimeImmutable('today');

        $pack = $this->service->generatePack(self::COUNTRY, 8);

        $this->assertNotEmpty($pack['players']);
        foreach ($pack['players'] as $player) {
            $age = (new \DateTimeImmutable($player['dateOfBirth']))->diff($today)->y;
            $this->assertGreaterThanOrEqual(
                $floor,
                $age,
                sprintf('generated a %d year old against a first-team floor of %d', $age, $floor),
            );
        }
    }

    /**
     * Regression: a founder's squad built at the tier-8 band and then placed in tier 3.
     *
     * In a 3-tier country that put the player roughly 45 points below every opponent, with
     * nothing logged and the diagnostic reporting it as correct. The band must come from the
     * country's REAL bottom tier.
     *
     * ── THE BAND IS A POTENTIAL RANGE, NOT AN ABILITY RANGE ──────────────────────────────
     *
     * Worth stating because the name says otherwise and the distinction changed what a
     * founder's squad is. `PlayerGenerationService::generate()`'s fourth parameter is
     * `$potentialRange`; `currentAbility` is then the mean of attributes drawn under
     * `abilityTarget x potential`, so it lands BELOW the band. Measured on EN, whose bands are
     * authored far lower than the consts: tier 3 is potential 20-40 and ability 2-34, tier 8 is
     * potential 2-7 and ability 1-5.
     *
     * The draw this replaced filtered on `currentAbility BETWEEN min AND max`, so a drawn
     * starter player had its ABILITY in the band while every NPC club around it had its
     * POTENTIAL in the band — two different bases for the same number, which made a founder's
     * squad systematically stronger than the division it was placed in. Generating it puts both
     * on one basis. That is a deliberate balance change, not an accident of the refactor.
     */
    public function testTheSquadIsGeneratedAtTheCountrysRealBottomTierBand(): void
    {
        $league = new League(self::COUNTRY, 3, 'StarterPackServiceTest League 3');
        $this->em->persist($league);
        $this->em->flush();
        $this->cleanup[] = $league;

        $tier = $this->service->resolveStarterTier(self::COUNTRY);
        $this->assertSame(3, $tier, 'the bottom tier of this country is 3, not 8');

        $pack = $this->service->generatePack(self::COUNTRY, $tier);
        $band = $this->service->resolveAbilityRange(self::COUNTRY, $tier);

        $this->assertNotEmpty($pack['players']);
        foreach ($pack['players'] as $player) {
            $this->assertGreaterThanOrEqual(
                $band['min'],
                $player['potential'],
                'the tier band is applied to POTENTIAL, exactly as it is for an NPC club',
            );
            $this->assertLessThanOrEqual($band['max'], $player['potential']);

            // And ability sits under it, never above — the derived-from-potential property.
            $this->assertLessThanOrEqual($player['potential'], $player['currentAbility']);
        }

        // Tier 3's band, not tier 8's: the whole point of the regression.
        $this->assertSame(WorldInitializationService::ABILITY_RANGES[3]['min'], $band['min']);
    }

    /**
     * The founder's squad and its NPC opponents are built from the same band on the same
     * basis — which is the substance of "generate it like an NPC club does".
     */
    public function testTheFoundersSquadUsesTheSameBandAsItsDivisionsNpcClubs(): void
    {
        $league = new League(self::COUNTRY, 3, 'StarterPackServiceTest Shared Band');
        $this->em->persist($league);
        $this->em->flush();
        $this->cleanup[] = $league;

        $starterBand = $this->service->resolveAbilityRange(self::COUNTRY, 3);
        $worldBand   = self::getContainer()->get(WorldInitializationService::class)
            ->resolveAbilityRange(self::COUNTRY, 3);

        $this->assertSame(
            [$worldBand['min'], $worldBand['max']],
            [$starterBand['min'], $starterBand['max']],
            'a founder and the clubs it plays must be generated from one band',
        );
    }

    /** With no pyramid at all there is nothing to resolve, so the old default stands. */
    public function testWithNoLeaguesAtAllTheDefaultStarterTierIsUsed(): void
    {
        $this->assertSame(
            StarterPackService::DEFAULT_STARTER_TIER,
            $this->service->resolveStarterTier('ZQ'),
        );
    }

    /**
     * The band precedence, unchanged by the move to generation and shared with
     * StarterPackDiagnosticService so an operator-facing check cannot drift from the real
     * thing.
     */
    public function testAbilityRangeFallsBackThroughItsThreeSources(): void
    {
        // No authored band for this country, but tier 3 exists as a const.
        $fromConst = $this->service->resolveAbilityRange(self::COUNTRY, 3);
        $this->assertSame('WorldInitializationService::ABILITY_RANGES', $fromConst['source']);
        $this->assertSame(WorldInitializationService::ABILITY_RANGES[3]['min'], $fromConst['min']);

        // No const for tier 99 either, so the hardcoded floor is the last resort.
        $fallback = $this->service->resolveAbilityRange(self::COUNTRY, 99);
        $this->assertSame('hardcoded_fallback_5_35', $fallback['source']);
        $this->assertSame(5, $fallback['min']);
        $this->assertSame(35, $fallback['max']);
    }

    /**
     * Priced with the country and tier, like an NPC club, so a founder's squad follows the
     * same economic model as the division it plays in.
     *
     * A pool-DRAWN starter player never did: `MarketPoolService` prices pool rows with no
     * country or tier, and the starter pack reaches the client through
     * `PlayerSnapshotSerializer`, which deliberately does not re-price. The founder's squad was
     * the one squad in the pyramid outside the economy.
     *
     * Asserts only that a wage is set and positive. The FIGURE is the wage model's business and
     * is covered by its own tests; under the shipped `legacy` model country and tier are
     * ignored, so asserting a turnover number here would pin behaviour that is switched off.
     */
    public function testGeneratedPlayersCarryAWage(): void
    {
        $pack = $this->service->generatePack(self::COUNTRY, 8);

        $this->assertNotEmpty($pack['players']);
        foreach ($pack['players'] as $player) {
            $this->assertArrayHasKey('contractValue', $player);
            $this->assertGreaterThan(0, $player['contractValue'], 'a generated player must be priced');
        }
    }
}
