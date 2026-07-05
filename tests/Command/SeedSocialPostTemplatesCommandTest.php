<?php

namespace App\Tests\Command;

use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Doctrine\ORM\EntityManagerInterface;

class SeedSocialPostTemplatesCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('app:seed-social-post-templates');
        return new CommandTester($command);
    }

    private function cleanUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(SocialPostTemplate::class)->findAll() as $t) {
            $em->remove($t);
        }
        $em->flush();
    }

    public function testSeedsEightTemplatesAndIsIdempotent(): void
    {
        self::bootKernel();
        $this->cleanUp();

        $tester = $this->tester();
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $all = $em->getRepository(SocialPostTemplate::class)->findAll();
        $this->assertCount(8, $all);

        // Running again must not create duplicates.
        $tester->execute([]);
        $em->clear();
        $allAfterSecondRun = $em->getRepository(SocialPostTemplate::class)->findAll();
        $this->assertCount(8, $allAfterSecondRun);

        foreach (StatCategory::cases() as $category) {
            foreach (SocialPlatform::cases() as $platform) {
                $match = array_filter($allAfterSecondRun, fn (SocialPostTemplate $t) => $t->getCategory() === $category && $t->getPlatform() === $platform);
                $this->assertCount(1, $match, "Expected exactly one template for {$category->value}/{$platform->value}");
            }
        }

        $this->cleanUp();
    }
}
