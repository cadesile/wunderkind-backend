<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\GameConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:economy:wage-table`'s report on the WEEKS deficit ladder.
 *
 * ── WHY THIS IS NOT REDUNDANT WITH THE SETTER'S OWN TESTS ─────────────────────────────────
 *
 * `GameConfig::normaliseDeductionWeeks()` repairs a ladder on write, and
 * `BankruptcyDeductionWeeksTest` pins the repairs. The danger that creates is the repair
 * itself: an operator types a ladder, the setter quietly fixes it, and they go away believing
 * they authored something they did not. **These checks exist to say what was changed**, which
 * is the one thing the setter cannot do from inside a save.
 *
 * So the rows below are written straight into the entity and flushed, bypassing the setter, to
 * reproduce what a database that predates the normaliser — or a direct SQL edit — actually
 * holds. Going through the setter would repair them and leave nothing to report, which would
 * make this file assert that a passing case passes.
 */
class EconomyDeductionWeeksCheckTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->store(null);

        parent::tearDown();
    }

    /** Writes the column directly, so the normaliser does not get a say. */
    private function store(?array $rows): void
    {
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig(flush: true);

        $em->getConnection()->executeStatement(
            'UPDATE game_config SET bankruptcy_deduction_weeks = :v WHERE id = :id',
            ['v' => $rows === null ? null : json_encode($rows), 'id' => $config->getId()],
        );
        $em->clear();
    }

    private function runGate(): string
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:economy:wage-table'),
        );
        $tester->execute(['countries' => ['EN']]);

        return $tester->getDisplay();
    }

    // ── Silent when it should be ─────────────────────────────────────────

    public function testNullReportsNothing(): void
    {
        // The shipped state. The app uses its own constant, so there is nothing to check and
        // nothing to say — a gate that complained here would never reach zero.
        $this->store(null);

        self::assertStringNotContainsString('bankruptcyDeductionWeeks', $this->runGate());
    }

    public function testTheAgreedLadderReportsNothing(): void
    {
        // The shape agreed with the app team must be silent, or the gate is unusable with the
        // configuration it is meant to bless.
        $this->store([
            ['weeksMin' => 1,  'weeksMax' => 2,    'points' => 3],
            ['weeksMin' => 2,  'weeksMax' => 4,    'points' => 6],
            ['weeksMin' => 4,  'weeksMax' => 8,    'points' => 10],
            ['weeksMin' => 8,  'weeksMax' => 16,   'points' => 20],
            ['weeksMin' => 16, 'weeksMax' => null, 'points' => 50],
        ]);

        self::assertStringNotContainsString('bankruptcyDeductionWeeks', $this->runGate());
    }

    // ── The four faults it must name ─────────────────────────────────────

    public function testAGapIsReportedBecauseADeficitWouldFallThroughIt(): void
    {
        // 1-2 then 4-8 leaves week 3 unpunished. Silent failure, exactly the kind a ladder
        // hides until somebody is three weeks in the red and nothing happens.
        $this->store([
            ['weeksMin' => 1, 'weeksMax' => 2,    'points' => 3],
            ['weeksMin' => 4, 'weeksMax' => null, 'points' => 10],
        ]);

        $out = $this->runGate();

        self::assertStringContainsString('bankruptcyDeductionWeeks row 2', $out);
        self::assertStringContainsString('gap', $out);
    }

    public function testAnOverlapIsReported(): void
    {
        $this->store([
            ['weeksMin' => 1, 'weeksMax' => 6,    'points' => 3],
            ['weeksMin' => 4, 'weeksMax' => null, 'points' => 10],
        ]);

        self::assertStringContainsString('overlap', $this->runGate());
    }

    public function testARowBehindAnUnboundedOneIsReportedAsUnreachable(): void
    {
        // The same defect bankruptcyDeductionTiers has today and cannot have repaired, because
        // that column is stored verbatim.
        $this->store([
            ['weeksMin' => 1, 'weeksMax' => null, 'points' => 3],
            ['weeksMin' => 4, 'weeksMax' => null, 'points' => 50],
        ]);

        self::assertStringContainsString('can never be reached', $this->runGate());
    }

    public function testALadderThatEndsLeavesTheWorstCaseUnpunished(): void
    {
        // No unbounded row means a club further behind than the last rung is deducted nothing
        // at all — the opposite of what the mechanic is for, and it looks like a complete
        // ladder right up until it matters.
        $this->store([
            ['weeksMin' => 1, 'weeksMax' => 2, 'points' => 3],
            ['weeksMin' => 2, 'weeksMax' => 4, 'points' => 6],
        ]);

        $out = $this->runGate();

        self::assertStringContainsString('no unbounded row', $out);
        self::assertStringContainsString('deducted nothing', $out);
    }
}
