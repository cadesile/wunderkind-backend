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
        $config->setLastPostedStatCategory(null);
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
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        $this->assertNull($config->getLastPostedStatCategory());
    }

    public function testRotatesThroughAllFourCategoriesInOrder(): void
    {
        self::bootKernel();
        $this->resetState();

        $em = $this->em();
        $encryption = self::getContainer()->get(TokenEncryptionService::class);
        $connection = new SocialAccountConnection(SocialPlatform::FACEBOOK, 'Test Page', 'page-rotation-test', $encryption->encrypt('fake-token'));
        $em->persist($connection);

        foreach (StatCategory::cases() as $category) {
            $em->persist(new SocialPostTemplate($category, SocialPlatform::FACEBOOK, StatsPeriod::ALL, 'Static text with no tokens.'));
        }
        $em->flush();

        $expectedOrder = StatCategory::cases();
        $tester = $this->tester();

        foreach ($expectedOrder as $expectedCategory) {
            $tester->execute([]);
            $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
            $this->assertSame($expectedCategory, $config->getLastPostedStatCategory());
        }

        // A 5th run wraps back around to the first category.
        $tester->execute([]);
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        $this->assertSame($expectedOrder[0], $config->getLastPostedStatCategory());

        $this->resetState();
    }
}
