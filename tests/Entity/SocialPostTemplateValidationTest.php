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
 * SocialPostTemplate carries a class-level #[UniqueEntity] constraint (added
 * after manual verification found the admin CRUD's "New" form 500ing on a
 * duplicate category/platform submission instead of showing a form error).
 * UniqueEntity's validator is a container service backed by Doctrine, so these
 * validation tests need the real service container — a standalone
 * Validation::createValidatorBuilder() can't resolve it. Each test cleans up
 * its chosen (category, platform) row first so it never collides with
 * whatever app:seed-social-post-templates may have already seeded into this DB.
 */
class SocialPostTemplateValidationTest extends KernelTestCase
{
    private function cleanUp(StatCategory $category, SocialPlatform $platform): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(SocialPostTemplate::class)->findBy([
            'category' => $category,
            'platform' => $platform,
        ]) as $existing) {
            $em->remove($existing);
        }
        $em->flush();
    }

    public function testTwitterTemplateOver280CharsFailsValidation(): void
    {
        self::bootKernel();
        $this->cleanUp(StatCategory::MOST_TRANSFERS, SocialPlatform::TWITTER);

        $validator = self::getContainer()->get(ValidatorInterface::class);

        $template = new SocialPostTemplate(
            StatCategory::MOST_TRANSFERS,
            SocialPlatform::TWITTER,
            StatsPeriod::WEEK,
            str_repeat('a', 281),
        );

        $violations = $validator->validate($template);

        $this->assertGreaterThan(0, count($violations));
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        $this->assertContains('bodyTemplate', $paths);
    }

    public function testFacebookTemplateOver280CharsPassesValidation(): void
    {
        self::bootKernel();
        $this->cleanUp(StatCategory::MOST_TRANSFERS, SocialPlatform::FACEBOOK);

        $validator = self::getContainer()->get(ValidatorInterface::class);

        $template = new SocialPostTemplate(
            StatCategory::MOST_TRANSFERS,
            SocialPlatform::FACEBOOK,
            StatsPeriod::WEEK,
            str_repeat('a', 281),
        );

        $violations = $validator->validate($template);

        $this->assertCount(0, $violations);
    }
}
