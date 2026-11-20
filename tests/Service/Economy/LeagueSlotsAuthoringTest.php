<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\League;
use App\Entity\NpcClub;
use App\Repository\LeagueRepository;
use App\Service\Economy\LeagueSizeResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `Version20261119090000` — authoring `league.slots` must change no division's size.
 *
 * ── WHY THIS TEST EXISTS IN THIS SHAPE ────────────────────────────────────────────────────
 *
 * The migration's whole promise is that the day it lands is byte-identical: it authors each
 * league's CURRENTLY DERIVED size, so `LeagueSizeResolver::sizeOf()` must answer the same number
 * before and after. That is not provable from the gate on the development clone, where all 120
 * league rows are already authored and the migration is a complete no-op — an unchanged
 * `app:economy:wage-table` diff there shows the command still runs, not that the authoring is
 * right. So the property is pinned here, against leagues deliberately seeded in the `slots = 0`
 * state the deployed box is actually in.
 *
 * It runs the migration's OWN statement, required by path and read off
 * `Version20261119090000::AUTHOR_SQL` — `wunderkind_test` is not migration-tracked, so the
 * migration cannot reach it, and a re-typed copy of the SQL would test the copy.
 *
 * ── ⚠️ HISTORICAL AS OF 2026-11-19: THE COLUMN THIS MIGRATION FILLS IS NO LONGER READ ────
 *
 * The owner retired `league.slots` (*"there should be no wiring around the amount of clubs, the
 * finances should be dynamic"*), so `sizeOf()` derives always and this migration's product
 * governs nothing. The file is kept, and still runs the real SQL, because a migration that has
 * already been applied to production boxes has to stay correct and re-runnable — not because
 * anything downstream depends on what it wrote.
 *
 * ⚠️ **One assertion changed meaning and was rewritten rather than deleted:**
 * `testAnAuthoredValueIsNeverOverwrittenEvenWhenItDisagreesWithTheCount` used to assert that
 * `sizeOf()` returned the authored 3 against 3 rows in a bottom tier. It now returns 4, which
 * is the ruling working. What that test still proves — that the MIGRATION leaves a populated
 * column alone — is unchanged and is what it now asserts.
 */
class LeagueSlotsAuthoringTest extends KernelTestCase
{
    /** Its own synthetic country, so the shared test database's real rows are untouched. */
    private const COUNTRY = 'ZS';

    private EntityManagerInterface $em;
    private LeagueRepository $leagues;
    private LeagueSizeResolver $sizes;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $container     = self::getContainer();
        $this->em      = $container->get(EntityManagerInterface::class);
        $this->leagues = $container->get(LeagueRepository::class);
        $this->sizes   = $container->get(LeagueSizeResolver::class);
        $this->db      = $container->get(Connection::class);

        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        parent::tearDown();
    }

    private function authoringSql(): string
    {
        require_once \dirname(__DIR__, 3) . '/migrations/Version20261119090000.php';

        return \DoctrineMigrations\Version20261119090000::AUTHOR_SQL;
    }

    /**
     * Seeds three tiers of one country: two mid tiers and a bottom one.
     *
     * @param array<int, int> $npcPerTier tier => how many NPC clubs to create in it
     */
    private function seed(array $npcPerTier, int $slots = 0): void
    {
        foreach ($npcPerTier as $tier => $count) {
            $league = new League(self::COUNTRY, $tier, 'Probe ' . $tier);
            $league->setSlots($slots);
            $this->em->persist($league);

            for ($i = 0; $i < $count; $i++) {
                $club = new NpcClub(
                    sprintf('Probe %d-%d', $tier, $i),
                    self::COUNTRY,
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

    /** @return array<int, int> tier => resolved size */
    private function sizesByTier(): array
    {
        $this->sizes->forget();
        $out = [];

        foreach ($this->leagues->findBy(['country' => self::COUNTRY]) as $league) {
            $out[$league->getTier()] = $this->sizes->sizeOf($league);
        }

        ksort($out);

        return $out;
    }

    /** @return array<int, int> tier => the authored column */
    private function slotsByTier(): array
    {
        $out = [];

        foreach ($this->leagues->findBy(['country' => self::COUNTRY]) as $league) {
            $out[$league->getTier()] = $league->getSlots();
        }

        ksort($out);

        return $out;
    }

    /**
     * THE CENTRAL PROPERTY: every division's resolved size is identical after authoring.
     *
     * Tier 8 is the bottom one, so its derived size is its NPC count PLUS ONE — the slot a new
     * player club is placed into by `LeagueService::resolveNewLeague()`. Getting that `+1` wrong
     * is the single most likely way to author a divisor that silently differs by one club.
     */
    public function testAuthoringLeavesEveryResolvedSizeUnchanged(): void
    {
        $this->seed([6 => 16, 7 => 16, 8 => 15]);

        $before = $this->sizesByTier();
        self::assertSame([6 => 16, 7 => 16, 8 => 16], $before, 'the bottom tier derives its NPC count + 1');

        $this->db->executeStatement($this->authoringSql());
        $this->em->clear();

        self::assertSame($before, $this->sizesByTier(), 'authoring moved a division size');
        self::assertSame(
            [6 => 16, 7 => 16, 8 => 16],
            $this->slotsByTier(),
            'the column must now hold the figure that was previously being derived',
        );
    }

    /**
     * ⚠️ AN ALREADY-AUTHORED VALUE IS A DECISION AND MUST SURVIVE.
     *
     * Overwriting it with the live club count is the exact side effect the migration exists to
     * abolish, and it is not hypothetical: the development clone holds SC tier 8 at an authored 3
     * while it derives 4, so "author every league to its derived size" would have moved a real
     * division on the day it landed.
     */
    public function testAnAuthoredValueIsNeverOverwrittenEvenWhenItDisagreesWithTheCount(): void
    {
        // The SC tier-8 shape exactly: authored 3, derives 4.
        $this->seed([8 => 3], slots: 3);

        // ⚠️ THE RESOLVED SIZE IS 4, NOT THE AUTHORED 3 — owner ruling, 2026-11-19. This line
        // used to assert 3 ("the authored value wins over the count") and is the one place in
        // this file where the ruling changed an expectation rather than a comment. The
        // migration's own promise, which is what this test is for, is unaffected: it must not
        // overwrite a column somebody populated, whatever the resolver now makes of it.
        self::assertSame([8 => 4], $this->sizesByTier(), 'the size must be derived, not read off the column');

        $this->db->executeStatement($this->authoringSql());
        $this->em->clear();

        self::assertSame([8 => 3], $this->slotsByTier(), 'an authored slots was overwritten by the derived count');
        self::assertSame([8 => 4], $this->sizesByTier(), 'the migration moved a resolved size');
    }

    /**
     * An empty non-bottom tier stays at 0, because 0 still means derive.
     *
     * Authoring a 0 would author nothing while looking like a decision. `sizeOf()` returns 0 for
     * such a tier deliberately — "callers that divide check for it rather than being handed a 1
     * that hides it" — so leaving it alone keeps that honest.
     */
    public function testAnEmptyNonBottomTierIsLeftDeriving(): void
    {
        $this->seed([6 => 0, 8 => 4]);

        $this->db->executeStatement($this->authoringSql());
        $this->em->clear();

        $slots = $this->slotsByTier();

        self::assertSame(0, $slots[6], 'an empty non-bottom tier must stay at 0 = derive');
        self::assertSame(5, $slots[8], 'the bottom tier still authors its count + 1');
    }

    /**
     * A club whose own country disagrees with its league's is not counted.
     *
     * `NpcClubRepository::countsByLeagueForCountry()` filters on the club's country as well as
     * its league, so the migration joins on both. A join on `league_id` alone would count this
     * club and author a divisor one larger than the one being replaced.
     */
    public function testAClubWhoseCountryDisagreesWithItsLeagueIsNotCounted(): void
    {
        $this->seed([6 => 4, 8 => 2]);

        $league = $this->leagues->findOneBy(['country' => self::COUNTRY, 'tier' => 6]);
        $stray  = new NpcClub('Stray Rovers', 'ZT', 6, 40, '#303030', '#404040', 1_000_000, []);
        $stray->setLeague($league);
        $this->em->persist($stray);
        $this->em->flush();
        $this->em->clear();

        $before = $this->sizesByTier();
        self::assertSame(4, $before[6], 'the resolver does not count the stray club');

        $this->db->executeStatement($this->authoringSql());
        $this->em->clear();

        self::assertSame(4, $this->slotsByTier()[6], 'the stray club was counted into the authored divisor');

        foreach ($this->leagues->findBy(['country' => 'ZT']) as $l) {
            $this->em->remove($l);
        }
        foreach ($this->em->getRepository(NpcClub::class)->findBy(['country' => 'ZT']) as $c) {
            $this->em->remove($c);
        }
        $this->em->flush();
    }

    private function removeFixtures(): void
    {
        foreach (['ZT', self::COUNTRY] as $country) {
            foreach ($this->em->getRepository(NpcClub::class)->findBy(['country' => $country]) as $club) {
                $this->em->remove($club);
            }
        }
        $this->em->flush();

        foreach (['ZT', self::COUNTRY] as $country) {
            foreach ($this->leagues->findBy(['country' => $country]) as $league) {
                $this->em->remove($league);
            }
        }
        $this->em->flush();
        $this->em->clear();
        $this->sizes->forget();
    }
}
