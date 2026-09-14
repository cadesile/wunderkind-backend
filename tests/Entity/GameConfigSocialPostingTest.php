<?php

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use App\Repository\GameConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GameConfigSocialPostingTest extends KernelTestCase
{
    public function testLastPostedCategoryForPeriodDefaultsToNull(): void
    {
        $config = new GameConfig();
        $this->assertNull($config->getLastPostedCategoryForPeriod(StatsPeriod::WEEK));
    }

    public function testLastPostedCategoryForPeriodCanBeSetAndRead(): void
    {
        $config = new GameConfig();
        $config->setLastPostedCategoryForPeriod(StatsPeriod::WEEK, StatCategory::MOST_SEASONS);
        $this->assertSame(StatCategory::MOST_SEASONS, $config->getLastPostedCategoryForPeriod(StatsPeriod::WEEK));
    }

    public function testRotationStateIsIndependentPerPeriod(): void
    {
        $config = new GameConfig();
        $config->setLastPostedCategoryForPeriod(StatsPeriod::WEEK, StatCategory::MOST_SEASONS);
        $config->setLastPostedCategoryForPeriod(StatsPeriod::MONTH, StatCategory::BEST_FORM);

        $this->assertSame(StatCategory::MOST_SEASONS, $config->getLastPostedCategoryForPeriod(StatsPeriod::WEEK));
        $this->assertSame(StatCategory::BEST_FORM, $config->getLastPostedCategoryForPeriod(StatsPeriod::MONTH));
        $this->assertNull($config->getLastPostedCategoryForPeriod(StatsPeriod::LAST_24_HOURS));
    }

    /**
     * Doctrine's json-column change-tracking only notices a NEW array instance, not an
     * in-place mutation — a plain getter/setter unit test wouldn't catch a real
     * flush-doesn't-persist bug here, so this round-trips through a real EntityManager.
     */
    public function testSetLastPostedCategoryForPeriodPersistsAcrossFlushAndClear(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(GameConfigRepository::class);

        $config = $repository->getConfig(flush: true);
        $config->setLastPostedCategoryForPeriod(StatsPeriod::WEEK, StatCategory::MOST_SEASONS);
        $config->setLastPostedCategoryForPeriod(StatsPeriod::MONTH, StatCategory::BEST_FORM);
        $em->flush();
        $em->clear();

        $reloaded = $repository->getConfig();
        $this->assertSame(StatCategory::MOST_SEASONS, $reloaded->getLastPostedCategoryForPeriod(StatsPeriod::WEEK));
        $this->assertSame(StatCategory::BEST_FORM, $reloaded->getLastPostedCategoryForPeriod(StatsPeriod::MONTH));
    }

    public function testAutoPostDisabledByDefault(): void
    {
        $config = new GameConfig();
        $this->assertFalse($config->isAutoPostEnabled(StatsPeriod::WEEK));
    }

    public function testSetAutoPostScheduleCanBeReadBack(): void
    {
        $config = new GameConfig();
        $config->setAutoPostSchedule(StatsPeriod::WEEK, true, 12);

        $this->assertTrue($config->isAutoPostEnabled(StatsPeriod::WEEK));
        $this->assertSame(12, $config->getAutoPostIntervalHours(StatsPeriod::WEEK));
        $this->assertFalse($config->isAutoPostEnabled(StatsPeriod::MONTH), 'schedule state must be independent per period');
    }

    public function testSetAutoPostScheduleClampsIntervalHoursToAtLeastOne(): void
    {
        $config = new GameConfig();
        $config->setAutoPostSchedule(StatsPeriod::WEEK, true, 0);

        $this->assertSame(1, $config->getAutoPostIntervalHours(StatsPeriod::WEEK));
    }

    public function testIsAutoPostDueIsFalseWhenDisabled(): void
    {
        $config = new GameConfig();
        $config->setAutoPostSchedule(StatsPeriod::WEEK, false, 6);

        $this->assertFalse($config->isAutoPostDue(StatsPeriod::WEEK, new \DateTimeImmutable()));
    }

    public function testIsAutoPostDueIsTrueWhenEnabledAndNeverRun(): void
    {
        $config = new GameConfig();
        $config->setAutoPostSchedule(StatsPeriod::WEEK, true, 6);

        $this->assertTrue($config->isAutoPostDue(StatsPeriod::WEEK, new \DateTimeImmutable()));
    }

    public function testIsAutoPostDueIsFalseBeforeIntervalElapses(): void
    {
        $config = new GameConfig();
        $config->setAutoPostSchedule(StatsPeriod::WEEK, true, 6);
        $now = new \DateTimeImmutable();
        $config->markAutoPostRun(StatsPeriod::WEEK, $now->modify('-3 hours'));

        $this->assertFalse($config->isAutoPostDue(StatsPeriod::WEEK, $now));
    }

    public function testIsAutoPostDueIsTrueAfterIntervalElapses(): void
    {
        $config = new GameConfig();
        $config->setAutoPostSchedule(StatsPeriod::WEEK, true, 6);
        $now = new \DateTimeImmutable();
        $config->markAutoPostRun(StatsPeriod::WEEK, $now->modify('-7 hours'));

        $this->assertTrue($config->isAutoPostDue(StatsPeriod::WEEK, $now));
    }

    public function testMarkAutoPostRunPersistsAcrossFlushAndClear(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(GameConfigRepository::class);

        $config = $repository->getConfig(flush: true);
        $config->setAutoPostSchedule(StatsPeriod::LAST_24_HOURS, true, 24);
        $now = new \DateTimeImmutable();
        $config->markAutoPostRun(StatsPeriod::LAST_24_HOURS, $now);
        $em->flush();
        $em->clear();

        $reloaded = $repository->getConfig();
        $this->assertTrue($reloaded->isAutoPostEnabled(StatsPeriod::LAST_24_HOURS));
        $this->assertSame(24, $reloaded->getAutoPostIntervalHours(StatsPeriod::LAST_24_HOURS));
        $this->assertEqualsWithDelta(
            $now->getTimestamp(),
            $reloaded->getLastAutoPostRunAt(StatsPeriod::LAST_24_HOURS)->getTimestamp(),
            1,
        );
    }
}
