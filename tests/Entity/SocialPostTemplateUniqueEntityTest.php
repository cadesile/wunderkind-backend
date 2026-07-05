<?php

namespace App\Tests\Entity;

use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * UniqueEntity needs the real Doctrine-backed validator service (it queries the
 * database to check for an existing row), so this is a KernelTestCase integration
 * test rather than a plain unit test — matching the pattern already used for
 * app:post-community-stat's rotation-state tests, which also need real persistence.
 */
class SocialPostTemplateUniqueEntityTest extends KernelTestCase
{
    private function cleanUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(SocialPostTemplate::class)->findBy([
            'category' => StatCategory::MOST_SEASONS,
            'platform' => SocialPlatform::TWITTER,
        ]) as $existing) {
            $em->remove($existing);
        }
        $em->flush();
    }

    public function testDuplicateCategoryPlatformFailsValidationInsteadOfThrowingOnFlush(): void
    {
        self::bootKernel();
        $this->cleanUp();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $validator = self::getContainer()->get(ValidatorInterface::class);

        $existing = new SocialPostTemplate(StatCategory::MOST_SEASONS, SocialPlatform::TWITTER, StatsPeriod::ALL, 'First one.');
        $em->persist($existing);
        $em->flush();

        $duplicate = new SocialPostTemplate(StatCategory::MOST_SEASONS, SocialPlatform::TWITTER, StatsPeriod::ALL, 'Second one — same category/platform.');

        $violations = $validator->validate($duplicate);

        $this->assertGreaterThan(0, count($violations), 'Expected a validation violation for the duplicate (category, platform) pair.');
        $this->assertStringContainsString('already exists', $violations[0]->getMessage());

        $this->cleanUp();
    }

    public function testDifferentPlatformForSameCategoryPassesValidation(): void
    {
        self::bootKernel();
        $this->cleanUp();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $validator = self::getContainer()->get(ValidatorInterface::class);

        $existing = new SocialPostTemplate(StatCategory::MOST_SEASONS, SocialPlatform::TWITTER, StatsPeriod::ALL, 'Twitter one.');
        $em->persist($existing);
        $em->flush();

        // Same category, different platform — not a duplicate, must pass.
        $notADuplicate = new SocialPostTemplate(StatCategory::MOST_SEASONS, SocialPlatform::FACEBOOK, StatsPeriod::ALL, 'Facebook one — already seeded, but that is a pre-existing row from app:seed-social-post-templates, not this test.');

        $violations = $validator->validate($notADuplicate);

        $this->assertCount(0, $violations);

        $this->cleanUp();
    }
}
