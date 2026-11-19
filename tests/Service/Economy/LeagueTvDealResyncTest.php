<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\League;
use App\Repository\LeagueRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `Version20261119150000` — resyncing the `tv_deal` cache that a `slots` correction left stale.
 *
 * ── WHAT THE DANGEROUS HALF IS ────────────────────────────────────────────────────────────
 *
 * The arithmetic is trivial (`pool / slots`); the risk is entirely in the WHERE clause. Two
 * kinds of league must come out of this migration byte-identical, and neither is exotic:
 *
 *  - **no pool.** `tv_deal` is then an AUTHORED absolute an operator typed, not a cache of
 *    anything, and `derivedTvDealPence()` deliberately returns null for it. Writing a derived
 *    figure over it would be inventing a pool nobody authored. This is the common case across
 *    the world and it is what makes shipping the migration provably inert for those rows.
 *  - **`slots = 0`.** That means "derive", and the size is resolved at read time from NPC rows.
 *    SQL has no business second-guessing it, and `intdiv` by zero is not a figure.
 *
 * Both are pinned below, because a WHERE clause is exactly the sort of thing a later edit
 * loosens by accident. The test runs the migration's OWN statement, read off the constant:
 * `wunderkind_test` is not migration-tracked, so a re-typed copy of the SQL would test the copy.
 */
class LeagueTvDealResyncTest extends KernelTestCase
{
    /** Its own synthetic country, so the shared test database's real rows are untouched. */
    private const COUNTRY = 'YT';

    private EntityManagerInterface $em;
    private LeagueRepository $leagues;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $container     = self::getContainer();
        $this->em      = $container->get(EntityManagerInterface::class);
        $this->leagues = $container->get(LeagueRepository::class);
        $this->db      = $container->get(Connection::class);

        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        parent::tearDown();
    }

    private function resyncSql(): string
    {
        require_once \dirname(__DIR__, 3) . '/migrations/Version20261119150000.php';

        return \DoctrineMigrations\Version20261119150000::RESYNC_SQL;
    }

    private function seedLeague(int $tier, int $slots, ?int $pool, ?int $tvDeal): void
    {
        $league = new League(self::COUNTRY, $tier, 'Probe ' . $tier);
        $league->setSlots($slots);
        $league->setTvPoolPence($pool);
        $league->setTvDeal($tvDeal);

        $this->em->persist($league);
        $this->em->flush();
        $this->em->clear();
    }

    private function tvDealAt(int $tier): ?int
    {
        return $this->leagues->findByCountryAndTier(self::COUNTRY, $tier)?->getTvDeal();
    }

    /**
     * THE DEPLOYED BOX'S DEFECT, to the penny. England tier 1 holds a £300,000,000 pool and
     * stored £16,666,666.66 — the pool over the OLD 18 — against a corrected size of 16. The
     * right figure is £18,750,000.00, which is 12.5% more.
     */
    public function testAStaleCacheIsResyncedToPoolOverTheCorrectedSlots(): void
    {
        $this->seedLeague(tier: 1, slots: 16, pool: 30_000_000_000, tvDeal: 1_666_666_666);

        self::assertSame(1, $this->db->executeStatement($this->resyncSql()), 'the stale row was not written');
        $this->em->clear();

        self::assertSame(1_875_000_000, $this->tvDealAt(1), 'the cache still encodes the old divisor');
    }

    /**
     * ⚠️ THE ROW THAT MUST NOT MOVE. No pool means `tv_deal` is the authored per-club figure,
     * not a cache — and it is deliberately a number that is NOT `pool / slots` for anything,
     * so a loosened WHERE clause would be visible here immediately.
     */
    public function testALeagueWithNoPoolKeepsItsAuthoredFigureUntouched(): void
    {
        $this->seedLeague(tier: 2, slots: 16, pool: null, tvDeal: 777_777_777);

        self::assertSame(0, $this->db->executeStatement($this->resyncSql()), 'a pool-less league was written');
        $this->em->clear();

        self::assertSame(777_777_777, $this->tvDealAt(2), 'an authored per-club figure was overwritten');
    }

    /**
     * A pool-less league with NO authored figure either must stay NULL rather than become 0.
     * `0` would publish "this division pays no TV money", which is a claim; NULL is the absence
     * of one, and `derivedTvDealPence() ?? getTvDeal()` passes it through as such.
     */
    public function testANullCacheWithNoPoolStaysNull(): void
    {
        $this->seedLeague(tier: 3, slots: 16, pool: null, tvDeal: null);

        self::assertSame(0, $this->db->executeStatement($this->resyncSql()));
        $this->em->clear();

        self::assertNull($this->tvDealAt(3), 'an absent figure became a claim');
    }

    /**
     * `slots = 0` means derive, so there is no authored divisor to cache against — and dividing
     * by it is not a figure. The row belongs to read-time resolution, not to this migration.
     */
    public function testADerivingLeagueIsLeftEntirelyAlone(): void
    {
        $this->seedLeague(tier: 4, slots: 0, pool: 90_000_000, tvDeal: 5_000_000);

        self::assertSame(0, $this->db->executeStatement($this->resyncSql()), 'a deriving league was written');
        $this->em->clear();

        self::assertSame(5_000_000, $this->tvDealAt(4));
    }

    /**
     * A league whose cache is already right must not be rewritten, so the statement is
     * re-runnable and a second deploy of it moves nothing.
     */
    public function testAnAlreadyCorrectCacheIsUntouchedAndTheStatementIsIdempotent(): void
    {
        $this->seedLeague(tier: 5, slots: 16, pool: 30_000_000_000, tvDeal: 1_875_000_000);

        self::assertSame(0, $this->db->executeStatement($this->resyncSql()), 'a correct cache was rewritten');

        $this->db->executeStatement($this->resyncSql());
        $this->em->clear();

        self::assertSame(1_875_000_000, $this->tvDealAt(5));
    }

    /**
     * Truncation matches `derivedTvDealPence()`'s `intdiv()` rather than rounding. Pinned
     * because the two figures are compared by equality all over the publication paths: a
     * migration that rounded up would make the stored cache disagree with the resolved value
     * by a penny forever, which is the cheapest possible way to reintroduce this whole class
     * of defect.
     */
    public function testTheDivisionTruncatesExactlyLikeIntdiv(): void
    {
        // 100,000,001 / 7 = 14,285,714.43 -> 14,285,714
        $this->seedLeague(tier: 6, slots: 7, pool: 100_000_001, tvDeal: 1);

        $this->db->executeStatement($this->resyncSql());
        $this->em->clear();

        self::assertSame(intdiv(100_000_001, 7), $this->tvDealAt(6));
        self::assertSame(14_285_714, $this->tvDealAt(6));
    }

    private function removeFixtures(): void
    {
        foreach ($this->leagues->findBy(['country' => self::COUNTRY]) as $league) {
            $this->em->remove($league);
        }

        $this->em->flush();
        $this->em->clear();
    }
}
