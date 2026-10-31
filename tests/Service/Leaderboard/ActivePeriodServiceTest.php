<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Service\Leaderboard\ActivePeriodService;
use App\Service\Leaderboard\LeaderboardPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which periods get leaderboard_entry rows written for them.
 *
 * The gating on isEnabled is not thrift — it is what closes the disable-then-re-enable
 * hazard structurally. While a definition is disabled its key is not emitted, so no rows
 * exist for it, so a later re-enable measures growth from the re-enable and cannot
 * retro-award the all-time leader.
 */
class ActivePeriodServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testAllTimeAndTheCurrentWeekAreAlwaysActive(): void
    {
        $at = new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('UTC'));

        $periods = $this->service()->activePeriodsFor(LeaderboardCategory::IRON_MAN, $at);

        self::assertContains(LeaderboardPeriod::ALL_TIME, $periods);
        self::assertContains('2026-W44', $periods);
    }

    public function testADisabledDefinitionContributesNoPeriod(): void
    {
        $at = new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('UTC'));

        $this->persistDefinition(LeaderboardCategory::IRON_MAN, AwardCadence::MONTHLY, enabled: false);
        $this->em->flush();

        $periods = $this->service()->activePeriodsFor(LeaderboardCategory::IRON_MAN, $at);

        self::assertNotContains('2026-M10', $periods, 'a disabled definition must not have rows written for it');
    }

    public function testAnEnabledDefinitionAddsItsCadenceKey(): void
    {
        $at = new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('UTC'));

        $this->persistDefinition(LeaderboardCategory::IRON_MAN, AwardCadence::MONTHLY, enabled: true);
        $this->em->flush();

        $periods = $this->service()->activePeriodsFor(LeaderboardCategory::IRON_MAN, $at);

        self::assertContains('2026-M10', $periods);
        self::assertContains(LeaderboardPeriod::ALL_TIME, $periods);
        self::assertContains('2026-W44', $periods);
    }

    public function testADefinitionOnAnotherCategoryDoesNotLeak(): void
    {
        $at = new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('UTC'));

        $this->persistDefinition(LeaderboardCategory::IRON_MAN, AwardCadence::QUARTERLY, enabled: true);
        $this->em->flush();

        $periods = $this->service()->activePeriodsFor(LeaderboardCategory::FANATICS, $at);

        self::assertNotContains('2026-Q4', $periods);
    }

    /** A weekly definition's key IS the ISO-week key, so it must not double up. */
    public function testAWeeklyDefinitionDoesNotDuplicateTheIsoWeekKey(): void
    {
        $at = new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('UTC'));

        $this->persistDefinition(LeaderboardCategory::IRON_MAN, AwardCadence::WEEKLY, enabled: true);
        $this->em->flush();

        $periods = $this->service()->activePeriodsFor(LeaderboardCategory::IRON_MAN, $at);

        self::assertSame($periods, array_values(array_unique($periods)));
        self::assertSame(1, count(array_filter($periods, static fn (string $p): bool => $p === '2026-W44')));
    }

    public function testAllActivePeriodsSpansEveryCategory(): void
    {
        $at = new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('UTC'));

        $this->persistDefinition(LeaderboardCategory::IRON_MAN, AwardCadence::MONTHLY, enabled: true);
        $this->persistDefinition(LeaderboardCategory::FANATICS, AwardCadence::QUARTERLY, enabled: true);
        $this->em->flush();

        $periods = $this->service()->allActivePeriods($at);

        self::assertContains(LeaderboardPeriod::ALL_TIME, $periods);
        self::assertContains('2026-W44', $periods);
        self::assertContains('2026-M10', $periods);
        self::assertContains('2026-Q4', $periods);
    }

    private function service(): ActivePeriodService
    {
        $service = self::getContainer()->get(ActivePeriodService::class);
        // The memo is per-service-instance and this test changes the definition table
        // between calls, which a request never does.
        $service->reset();

        return $service;
    }

    private function persistDefinition(LeaderboardCategory $category, AwardCadence $cadence, bool $enabled): LeaderboardAwardDefinition
    {
        $definition = new LeaderboardAwardDefinition();
        $definition
            ->setName('Active Period Test')
            ->setCategory($category)
            ->setCadence($cadence)
            ->setIsEnabled($enabled);

        $this->em->persist($definition);
        $this->cleanup[] = $definition;

        return $definition;
    }
}
