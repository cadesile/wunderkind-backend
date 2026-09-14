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

class PostCommunityStatCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('app:post-community-stat');
        return new CommandTester($command);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Resets rotation state and removes all connections/templates created by this test class. */
    private function resetState(): void
    {
        $em = $this->em();
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig(flush: true);
        $config->resetStatPostRotation();
        $em->flush();

        foreach ($em->getRepository(SocialAccountConnection::class)->findAll() as $c) {
            $em->remove($c);
        }
        foreach ($em->getRepository(SocialPostTemplate::class)->findAll() as $t) {
            $em->remove($t);
        }
        $em->flush();
    }

    public function testDoesNotAdvanceRotationWithNoActiveConnections(): void
    {
        self::bootKernel();
        $this->resetState();

        $tester = $this->tester();
        $tester->execute(['period' => 'week']);

        $this->assertSame(0, $tester->getStatusCode());
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        $this->assertNull($config->getLastPostedCategoryForPeriod(StatsPeriod::WEEK));
    }

    public function testInvalidPeriodArgumentFailsWithClearError(): void
    {
        self::bootKernel();
        $this->resetState();

        $tester = $this->tester();
        $tester->execute(['period' => 'bogus']);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid period', $tester->getDisplay());
    }

    public function testRotatesThroughAllCategoriesInOrder(): void
    {
        self::bootKernel();
        $this->resetState();

        $em = $this->em();
        $encryption = self::getContainer()->get(TokenEncryptionService::class);
        $connection = new SocialAccountConnection(SocialPlatform::FACEBOOK, 'Test Page', 'page-rotation-test', $encryption->encrypt('fake-token'));
        $em->persist($connection);

        foreach (StatCategory::cases() as $category) {
            $em->persist(new SocialPostTemplate($category, SocialPlatform::FACEBOOK, StatsPeriod::WEEK, 'Static text with no tokens.'));
        }
        $em->flush();

        $expectedOrder = StatCategory::cases();
        $tester = $this->tester();

        foreach ($expectedOrder as $expectedCategory) {
            $tester->execute(['period' => 'week']);
            $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
            $this->assertSame($expectedCategory, $config->getLastPostedCategoryForPeriod(StatsPeriod::WEEK));
        }

        // Wraps back around to the first category after a full cycle.
        $tester->execute(['period' => 'week']);
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        $this->assertSame($expectedOrder[0], $config->getLastPostedCategoryForPeriod(StatsPeriod::WEEK));

        $this->resetState();
    }

    /**
     * The entire point of the per-period redesign: one period's rotation cursor must
     * not move when a different period is invoked.
     */
    public function testRotationStateIsIndependentPerPeriod(): void
    {
        self::bootKernel();
        $this->resetState();

        $em = $this->em();
        $encryption = self::getContainer()->get(TokenEncryptionService::class);
        $connection = new SocialAccountConnection(SocialPlatform::FACEBOOK, 'Test Page', 'page-independence-test', $encryption->encrypt('fake-token'));
        $em->persist($connection);

        foreach (StatCategory::cases() as $category) {
            $em->persist(new SocialPostTemplate($category, SocialPlatform::FACEBOOK, StatsPeriod::WEEK, 'Week text.'));
            $em->persist(new SocialPostTemplate($category, SocialPlatform::FACEBOOK, StatsPeriod::MONTH, 'Month text.'));
        }
        $em->flush();

        $tester = $this->tester();
        $tester->execute(['period' => 'week']);
        $tester->execute(['period' => 'week']);
        $tester->execute(['period' => 'month']);

        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        $cases = StatCategory::cases();

        // week ran twice: cursor sits at index 1.
        $this->assertSame($cases[1], $config->getLastPostedCategoryForPeriod(StatsPeriod::WEEK));
        // month ran once: cursor sits at index 0, unaffected by week's two runs.
        $this->assertSame($cases[0], $config->getLastPostedCategoryForPeriod(StatsPeriod::MONTH));

        $this->resetState();
    }
}
