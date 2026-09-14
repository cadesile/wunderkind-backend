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
}
