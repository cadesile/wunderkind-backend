<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\League;
use App\Entity\Location\CountryProfile;
use App\Repository\GameConfigRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:economy:wage-table`'s check 6 — the one guard on the one money figure the factor chain
 * cannot reach.
 *
 * ── WHAT IS BEING GUARDED, AND WHY CODE CANNOT JUST FIX IT ────────────────────────────────
 *
 * Every other amount in this economy is a base-year figure multiplied by the country factor at
 * resolve time, so a country set to 20% gets 20% turnover, 20% wages and 20% sponsorship for
 * free. `league.tv_deal` is different in kind: it is a STORED ABSOLUTE on a per-country row,
 * typed in by an operator, and nothing multiplies it. Set a factor and that country's clubs pay
 * a fifth of the wages while banking full baseline TV money — which is not a cheaper country,
 * it is a different game.
 *
 * It cannot be fixed by scaling in code, because the figure is authored rather than derived:
 * the server has no way to tell an intentionally-generous TV deal from an unscaled one. So the
 * remedy is a gate that tells the operator the figure they should have authored.
 *
 * ── WHY CHECK 3 DOES NOT ALREADY CATCH IT ─────────────────────────────────────────────────
 *
 * Check 3 fails only when TV exceeds 100% of turnover. A country at factor 0.5 with an unscaled
 * table is at twice its intended share and still passes, because the intended share at tier 1
 * is 35%. The bound catches absurdity, not disproportion — asserted below so nobody concludes
 * the two checks are redundant and deletes this one.
 *
 * ── WHY THE SHARE IS THE SIGNAL ───────────────────────────────────────────────────────────
 *
 * Turnover IS scaled, so for a correctly-authored table `TV / turnover` is the SAME at a given
 * tier in every country. That identity is what makes a cross-country comparison meaningful at
 * all, and it is why the check reads the baseline country's own `tv_deal` rather than trying to
 * judge a figure in isolation.
 */
class EconomyTvCountryScalingGuardTest extends KernelTestCase
{
    /**
     * TWO SYNTHETIC CODES, and that is not fussiness. An earlier draft used 'EN' as the
     * baseline, which meant the purge deleted real England rows from the shared test database
     * — and failed anyway, because `location.code` has a foreign key onto `country_profile`
     * and EN has locations. A test that needs to own a country must invent one.
     */
    private const BASE  = 'ZY';
    private const OTHER = 'ZZ';

    /** Tier-1 TV for the baseline, in pence: £136,000,000 — the figure authored on dev. */
    private const BASE_TV = 13_600_000_000;

    /**
     * The turnover table as the suite found it, stashed so `seed()`'s cannot outlive this file.
     *
     * ⚠️ `game_config` IS A SINGLE SHARED ROW in a test database that is not migration-tracked,
     * and `seed()` has to author a turnover table or no tier resolves and the preview
     * short-circuits before the checks this file is about are reached. So the row is owned for
     * the duration and put back, rather than left holding a figure the next test reads.
     *
     * ⚠️ RESTORED IN `tearDown()`, NOT AT THE END OF EACH TEST, AND THAT IS THE POINT: PHPUnit
     * runs `tearDown()` after a failed assertion too, so the row goes back exactly when
     * something has already gone wrong — which is when a dirty shared row costs the most,
     * because the next failure is then someone else's to attribute.
     *
     * @var list<int>
     */
    private array $shippedTurnoverPence = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->shippedTurnoverPence = self::getContainer()
            ->get(GameConfigRepository::class)
            ->getConfig(flush: true)
            ->getEconomyBaselineTurnoverPence();

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->restoreTurnoverTable();
        $this->purge();

        parent::tearDown();
    }

    /** Put the shared turnover table back, whatever happened to the test. */
    private function restoreTurnoverTable(): void
    {
        if ($this->shippedTurnoverPence === []) {
            return; // setUp() never got as far as stashing it; nothing authored, nothing to undo.
        }

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::getContainer()
            ->get(GameConfigRepository::class)
            ->getConfig(flush: true)
            ->setEconomyBaselineTurnoverPence($this->shippedTurnoverPence);

        $em->flush();
    }

    private function purge(): void
    {
        $c = self::getContainer()->get(Connection::class);
        $c->executeStatement('DELETE FROM league WHERE country IN (:c)', ['c' => [self::BASE, self::OTHER]], ['c' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $c->executeStatement('DELETE FROM country_profile WHERE code IN (:c)', ['c' => [self::BASE, self::OTHER]], ['c' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * One baseline country and one other, each with a single tier-1 league.
     *
     * @param int      $otherFactorBp the other country's stored factor
     * @param int|null $otherTv       its authored tier-1 TV; null means "left at the baseline's"
     */
    private function seed(int $otherFactorBp, ?int $otherTv = null): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $base = new CountryProfile(self::BASE, 'Baseland');
        $base->setIsEconomyBaseline(true);
        $base->setEconomyFactorBp(10000);
        $base->setLeagueTierCount(1);
        $em->persist($base);

        $other = new CountryProfile(self::OTHER, 'Otherland');
        $other->setEconomyFactorBp($otherFactorBp);
        $other->setLeagueTierCount(1);
        $em->persist($other);

        foreach ([[self::BASE, self::BASE_TV], [self::OTHER, $otherTv ?? self::BASE_TV]] as [$code, $tv]) {
            $league = new League($code, 1, "{$code} Premier");
            $league->setSlots(18);
            $league->setTvDeal($tv);
            $league->setTvPoolPence($tv * 18);
            $em->persist($league);
        }

        // A turnover table, or no tier resolves and the whole preview short-circuits.
        // Stashed in setUp() and put back in tearDown() — see $shippedTurnoverPence.
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig(flush: true);
        $config->setEconomyBaselineTurnoverPence([38_866_153_952, 0, 0, 0, 0, 0, 0, 0]);
        $em->flush();
    }

    private function runGate(string $country): CommandTester
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:economy:wage-table'),
        );
        $tester->execute(['countries' => [$country]]);

        return $tester;
    }

    // ── It fires on the defect ───────────────────────────────────────────
    //
    // REVERSED 2026-11-20. The factor is now applied where the TV pool is RESOLVED, so the defect
    // is no longer "TV was never scaled" — it is a pool an operator PRE-scaled by hand (as the old
    // message instructed), which would now be scaled a second time.

    public function testAFactoredCountryWithAPreScaledPoolIsReported(): void
    {
        $this->seed(otherFactorBp: 2000, otherTv: 2_720_000_000);   // 20% of £136m, typed in by hand

        $out = $this->runGate(self::OTHER)->getDisplay();

        self::assertStringContainsString('scaled twice', $out);
        // It names the figure that WOULD result, so the operator sees the damage.
        self::assertStringContainsString('Author the baseline figure', $out);
    }

    public function testTheGuardFailsTheCommand(): void
    {
        $this->seed(otherFactorBp: 2000, otherTv: 2_720_000_000);

        self::assertSame(1, $this->runGate(self::OTHER)->getStatusCode());
    }

    public function testItAlsoCatchesAFactorABOVEParity(): void
    {
        $this->seed(otherFactorBp: 20000, otherTv: 27_200_000_000);   // 2x, pre-scaled

        self::assertStringContainsString('scaled twice', $this->runGate(self::OTHER)->getDisplay());
    }

    // ── It stays silent when it should ───────────────────────────────────

    public function testACountryAtParityIsNotReported(): void
    {
        $this->seed(otherFactorBp: 10000);

        self::assertStringNotContainsString('scaled twice', $this->runGate(self::OTHER)->getDisplay());
    }

    public function testAPoolAuthoredInBaselineTermsIsNotReported(): void
    {
        // THE NEW CORRECT STATE: the same pool as the baseline, with the factor applied at
        // resolution. The table shows the resolved figure and the check stays quiet.
        $this->seed(otherFactorBp: 2000);

        $out = $this->runGate(self::OTHER)->getDisplay();

        self::assertStringNotContainsString('scaled twice', $out);
        self::assertStringContainsString('applied at resolution', $out);
        self::assertStringContainsString('489,600,000.00', $out);   // seed(): pool = 18 x £136m = £2,448m; x 0.2 = £489.6m
    }

    public function testRoundingToSignificantFiguresIsNotAFailure(): void
    {
        // Two per cent tolerance: £136.9m against £136m is 0.66% out.
        $this->seed(otherFactorBp: 2000, otherTv: 13_690_000_000);

        self::assertStringNotContainsString('scaled twice', $this->runGate(self::OTHER)->getDisplay());
    }

    public function testTheBaselineCountryIsNeverComparedWithItself(): void
    {
        $this->seed(otherFactorBp: 2000);

        self::assertStringNotContainsString('scaled twice', $this->runGate(self::BASE)->getDisplay());
    }
}
