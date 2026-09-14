<?php

namespace App\Tests\Command;

use App\Entity\SocialAccountConnection;
use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use App\Repository\GameConfigRepository;
use App\Service\TokenEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PostCommunityStatTickCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('app:post-community-stat-tick');
        return new CommandTester($command);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function config(): \App\Entity\GameConfig
    {
        return self::getContainer()->get(GameConfigRepository::class)->getConfig(flush: true);
    }

    private function resetState(): void
    {
        $em = $this->em();
        $config = $this->config();
        $config->resetStatPostRotation();
        $config->resetAutoPostSchedule();
        $em->flush();

        foreach ($em->getRepository(SocialAccountConnection::class)->findAll() as $c) {
            $em->remove($c);
        }
        foreach ($em->getRepository(SocialPostTemplate::class)->findAll() as $t) {
            $em->remove($t);
        }
        $em->flush();
    }

    public function testDoesNothingWhenNoPeriodIsEnabled(): void
    {
        self::bootKernel();
        $this->resetState();

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No period is due', $tester->getDisplay());
    }

    public function testDoesNothingWhenEnabledButIntervalHasNotElapsed(): void
    {
        self::bootKernel();
        $this->resetState();

        $config = $this->config();
        $config->setAutoPostSchedule(StatsPeriod::WEEK, true, 24);
        $config->markAutoPostRun(StatsPeriod::WEEK, new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();

        $tester = $this->tester();
        $tester->execute([]);

        $reloaded = $this->config();
        $this->assertNull($reloaded->getLastPostedCategoryForPeriod(StatsPeriod::WEEK), 'rotation must not advance for a period that is not due');
    }

    public function testDispatchesPostCommandAndAdvancesRotationForADuePeriod(): void
    {
        self::bootKernel();
        $this->resetState();

        $em = $this->em();
        $encryption = self::getContainer()->get(TokenEncryptionService::class);
        $connection = new SocialAccountConnection(SocialPlatform::FACEBOOK, 'Test Page', 'page-tick-test', $encryption->encrypt('fake-token'));
        $em->persist($connection);

        foreach (StatCategory::cases() as $category) {
            $em->persist(new SocialPostTemplate($category, SocialPlatform::FACEBOOK, StatsPeriod::LAST_24_HOURS, 'Static text with no tokens.'));
        }

        $config = $this->config();
        $config->setAutoPostSchedule(StatsPeriod::LAST_24_HOURS, true, 24);
        $em->flush();

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());

        $reloaded = $this->config();
        $this->assertSame(StatCategory::cases()[0], $reloaded->getLastPostedCategoryForPeriod(StatsPeriod::LAST_24_HOURS));
        $this->assertNotNull($reloaded->getLastAutoPostRunAt(StatsPeriod::LAST_24_HOURS));

        $this->resetState();
    }

    public function testMarksAsRunEvenWithNoActiveConnectionsSoItDoesNotRetryImmediately(): void
    {
        self::bootKernel();
        $this->resetState();

        $config = $this->config();
        $config->setAutoPostSchedule(StatsPeriod::WEEK, true, 6);
        $this->em()->flush();

        $tester = $this->tester();
        $tester->execute([]);

        $reloaded = $this->config();
        $this->assertNotNull($reloaded->getLastAutoPostRunAt(StatsPeriod::WEEK));
        $this->assertFalse($reloaded->isAutoPostDue(StatsPeriod::WEEK, new \DateTimeImmutable()));

        $this->resetState();
    }

    public function testOnlyDuePeriodsAreProcessedIndependently(): void
    {
        self::bootKernel();
        $this->resetState();

        $config = $this->config();
        $config->setAutoPostSchedule(StatsPeriod::WEEK, true, 24);
        $config->markAutoPostRun(StatsPeriod::WEEK, new \DateTimeImmutable('-1 hour')); // not due
        $config->setAutoPostSchedule(StatsPeriod::MONTH, true, 24); // never run -> due
        $this->em()->flush();

        $tester = $this->tester();
        $tester->execute([]);

        $reloaded = $this->config();
        $this->assertNull($reloaded->getLastPostedCategoryForPeriod(StatsPeriod::WEEK), 'week is not due and must be untouched');
        $this->assertNotNull($reloaded->getLastAutoPostRunAt(StatsPeriod::MONTH), 'month was due and must have been ticked');

        $this->resetState();
    }
}
