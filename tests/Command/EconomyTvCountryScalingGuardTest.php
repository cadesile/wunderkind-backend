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

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
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

    public function testAFactoredCountryWithAnUnscaledTvTableIsReported(): void
    {
        // THE DEFECT. Otherland is set to a fifth of the base year and its TV was never
        // re-authored, so its clubs would pay a fifth of the wages on full baseline TV money.
        $this->seed(otherFactorBp: 2000);

        $out = $this->runGate(self::OTHER)->getDisplay();

        self::assertStringContainsString('TV is', $out);
        self::assertStringContainsString('stored absolute', $out);
        // It names the figure to author, not merely that something is wrong: 20% of £136m.
        self::assertStringContainsString('27,200,000.00', $out);
    }

    public function testTheGuardFailsTheCommand(): void
    {
        // It is a GATE, so this has to change the exit code rather than print a warning.
        $this->seed(otherFactorBp: 2000);

        self::assertSame(1, $this->runGate(self::OTHER)->getStatusCode());
    }

    public function testItAlsoCatchesAFactorABOVEParity(): void
    {
        // A country deliberately richer than the base year has the same problem in reverse: its
        // TV is now UNDER-stated relative to its wages. The guard is about proportion, not
        // about being cheap.
        $this->seed(otherFactorBp: 20000);

        $out = $this->runGate(self::OTHER)->getDisplay();

        self::assertStringContainsString('stored absolute', $out);
        self::assertStringContainsString('272,000,000.00', $out);   // 2x £136m
    }

    // ── It stays silent when it should ───────────────────────────────────

    public function testACountryAtParityIsNotReported(): void
    {
        // Every country on every box today. The stored absolute is already correct at parity,
        // so a guard that fired here would make the gate unusable.
        $this->seed(otherFactorBp: 10000);

        self::assertStringNotContainsString('stored absolute', $this->runGate(self::OTHER)->getDisplay());
    }

    public function testACorrectlyScaledTableIsNotReported(): void
    {
        // The state an operator reaches after acting on the message: 20% of £136m = £27.2m.
        $this->seed(otherFactorBp: 2000, otherTv: 2_720_000_000);   // 20% of £136m = £27,200,000

        self::assertStringNotContainsString('stored absolute', $this->runGate(self::OTHER)->getDisplay());
    }

    public function testRoundingToSignificantFiguresIsNotAFailure(): void
    {
        // A sensible TV table is authored at a few significant figures, so the check tolerates
        // 2%. £27,000,000 against an exact £27,200,000 is 0.7% out and must pass, or the gate
        // would demand figures no human would type.
        $this->seed(otherFactorBp: 2000, otherTv: 2_700_000_000);   // £27,000,000 — 0.7% under exact

        self::assertStringNotContainsString('stored absolute', $this->runGate(self::OTHER)->getDisplay());
    }

    public function testTheBaselineCountryIsNeverComparedWithItself(): void
    {
        // It is parity with itself by definition, whatever factor it stores — the same
        // normalisation `countryFactorBp()` applies.
        $this->seed(otherFactorBp: 2000);

        self::assertStringNotContainsString('stored absolute', $this->runGate(self::BASE)->getDisplay());
    }

    // ── Why check 3 is not enough ────────────────────────────────────────

    public function testCheck3CannotCatchThisAndThatIsWhyCheck6Exists(): void
    {
        // THE JUSTIFICATION FOR A SEPARATE CHECK, asserted rather than claimed in a comment.
        // At factor 0.5 an unscaled table is at TWICE its intended share — plainly wrong — but
        // TV is still only 70% of turnover, so check 3's 100% bound says nothing. Check 6 must
        // be the one that speaks.
        $this->seed(otherFactorBp: 5000);

        $out = $this->runGate(self::OTHER)->getDisplay();

        self::assertStringNotContainsString('TV alone is', $out, 'check 3 stays quiet here');
        self::assertStringContainsString('stored absolute', $out, 'check 6 is what catches it');
    }
}
