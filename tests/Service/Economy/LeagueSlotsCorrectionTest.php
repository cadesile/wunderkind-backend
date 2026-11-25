<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Repository\LeagueRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `Version20261119120000` — correcting an AUTHORED `slots` that disagrees with the real size.
 *
 * ── WHY THIS TEST IS SHAPED AROUND THE RESERVED SLOT ──────────────────────────────────────
 *
 * Unlike `Version20261119090000`, this migration deliberately overwrites authored values, so the
 * only thing standing between it and silently re-pricing a division is whether it computes the
 * target correctly. The dangerous half is the **reserved player slot**: the world generator
 * leaves one place open at a country's deepest tier, and `LeagueSizeResolver::sizeOf()` adds 1
 * there unconditionally.
 *
 * **That assumption is false on some boxes, and it was measured false.** On the development clone,
 * 2026-11-19, EN reserves (18 rows at tiers 1-7, 17 at tier 8) but CA, JP, NG, PL, US and SC do
 * not — every tier including the deepest holds the same count. An earlier draft of this migration
 * used `sizeOf()`'s rule and would have written 9 over a correct 8 in five countries, and 4 over
 * SC's correct 3.
 *
 * So the migration DETECTS the reservation (deepest tier holding exactly one fewer row than the
 * tier above it) instead of assuming it, and both shapes are pinned here. It runs the migration's
 * OWN statement, read off the constant: `wunderkind_test` is not migration-tracked, and a
 * re-typed copy of the SQL would test the copy.
 */
class LeagueSlotsCorrectionTest extends KernelTestCase
{
    /** Its own synthetic countries, so the shared test database's real rows are untouched. */
    private const RESERVED   = 'ZR';
    private const UNRESERVED = 'ZU';

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

    private function correctionSql(): string
    {
        require_once \dirname(__DIR__, 3) . '/migrations/Version20261119120000.php';

        return \DoctrineMigrations\Version20261119120000::CORRECT_SQL;
    }

    /**
     * @param array<int, int> $npcPerTier tier => how many NPC clubs to create in it
     * @param array<int, int> $slotsPerTier tier => the authored column
     */
    private function seed(string $country, array $npcPerTier, array $slotsPerTier): void
    {
        foreach ($npcPerTier as $tier => $count) {
            $league = new League($country, $tier, 'Probe ' . $tier);
            $league->setSlots($slotsPerTier[$tier]);
            $this->em->persist($league);

            for ($i = 0; $i < $count; $i++) {
                $club = new NpcClub(
                    sprintf('Probe %s-%d-%d', $country, $tier, $i),
                    $country,
                    $tier,
                    40,
                    '#101010',
                    '#202020',
                    1_000_000,
                    [],
                );
                $club->setLeague($league);
                $this->em->persist($club);
            }
        }

        $this->em->flush();
        $this->em->clear();
    }

    /** @return array<int, int> tier => the authored column */
    private function slotsByTier(string $country): array
    {
        $out = [];

        foreach ($this->leagues->findBy(['country' => $country]) as $league) {
            $out[$league->getTier()] = $league->getSlots();
        }

        ksort($out);

        return $out;
    }

    /**
     * THE DEPLOYED BOX'S DEFECT: EN reads 18 against 16 real rows at tiers 1-7 and 15 at tier 8,
     * where a place IS reserved. The target is 16 at every tier — including the bottom one, where
     * 15 rows plus the reserved place is also 16.
     */
    public function testAReservedBottomTierIsCorrectedToRowsPlusTheReservedPlace(): void
    {
        $this->seed(self::RESERVED, [6 => 16, 7 => 16, 8 => 15], [6 => 18, 7 => 18, 8 => 18]);

        $this->db->executeStatement($this->correctionSql());
        $this->em->clear();

        self::assertSame(
            [6 => 16, 7 => 16, 8 => 16],
            $this->slotsByTier(self::RESERVED),
            'the purge residue was not corrected to the real size',
        );
    }

    /**
     * THE ROW THE NAIVE RULE WOULD HAVE BROKEN. This country never reserved a place: its deepest
     * tier holds the same count as the tier above. `sizeOf()`'s unconditional +1 would make the
     * target 9, which is one more club than the division can field.
     */
    public function testAnUnreservedBottomTierIsNotGivenAPhantomExtraPlace(): void
    {
        $this->seed(self::UNRESERVED, [6 => 8, 7 => 8, 8 => 8], [6 => 8, 7 => 8, 8 => 8]);

        $this->db->executeStatement($this->correctionSql());
        $this->em->clear();

        self::assertSame(
            [6 => 8, 7 => 8, 8 => 8],
            $this->slotsByTier(self::UNRESERVED),
            'an unreserved bottom tier was given a tenth... ninth place that does not exist',
        );
    }

    /**
     * `slots = 0` means "derive" and belongs to `Version20261119090000`. If this migration also
     * wrote those rows the two would fight, and a rollback of either would restore a value the
     * other had overwritten.
     */
    public function testADerivingLeagueIsLeftEntirelyAlone(): void
    {
        $this->seed(self::RESERVED, [6 => 16, 7 => 16, 8 => 15], [6 => 0, 7 => 0, 8 => 0]);

        $this->db->executeStatement($this->correctionSql());
        $this->em->clear();

        self::assertSame(
            [6 => 0, 7 => 0, 8 => 0],
            $this->slotsByTier(self::RESERVED),
            'a deriving league was authored by the wrong migration',
        );
    }

    /** The 184 leagues that already agree must not be rewritten, so the statement is re-runnable. */
    public function testAnAlreadyCorrectLeagueIsUntouchedAndTheStatementIsIdempotent(): void
    {
        $this->seed(self::RESERVED, [6 => 16, 7 => 16, 8 => 15], [6 => 16, 7 => 16, 8 => 16]);

        $first = $this->db->executeStatement($this->correctionSql());
        self::assertSame(0, $first, 'an already-correct league was rewritten');

        $this->db->executeStatement($this->correctionSql());
        $this->em->clear();

        self::assertSame([6 => 16, 7 => 16, 8 => 16], $this->slotsByTier(self::RESERVED));
    }

    private function removeFixtures(): void
    {
        foreach ([self::RESERVED, self::UNRESERVED] as $country) {
            foreach ($this->em->getRepository(NpcClub::class)->findBy(['country' => $country]) as $club) {
                $this->em->remove($club);
            }
        }
        $this->em->flush();

        $leagues = [];
        foreach ([self::RESERVED, self::UNRESERVED] as $country) {
            foreach ($this->leagues->findBy(['country' => $country]) as $league) {
                $leagues[] = $league;
            }
        }

        // PLAYER clubs too, not only NpcClub. `club.current_league_id` is a NO ACTION foreign
        // key, so one real Club pointing at a ZR/ZU league makes the league delete below fail
        // with a constraint violation and takes all four tests with it.
        //
        // That is not hypothetical and it is not this test's own doing: ZR and ZU are only
        // reserved by convention, and a sibling test that invents a random two-letter code
        // (LeagueServiceConcludeSeasonIdempotencyTest, which checks only that the code has no
        // league yet) can legitimately land on one of them. When that run dies before its own
        // tearDown — as the 2026-11-24 schema-drift run did — its club and league stay behind
        // and this test inherits them. Clearing its own country space completely is what makes
        // it immune to that rather than merely unlucky.
        foreach ($leagues as $league) {
            foreach ($this->em->getRepository(Club::class)->findBy(['currentLeague' => $league]) as $club) {
                $this->em->remove($club);
            }
        }
        $this->em->flush();

        foreach ($leagues as $league) {
            $this->em->remove($league);
        }
        $this->em->flush();
        $this->em->clear();
    }
}
