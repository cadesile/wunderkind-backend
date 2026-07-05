<?php

namespace App\Tests\Entity;

use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use PHPUnit\Framework\TestCase;

class SocialPostTemplateTest extends TestCase
{
    public function testConstructorSetsFieldsAndDefaults(): void
    {
        $template = new SocialPostTemplate(
            StatCategory::MOST_TRANSFERS,
            SocialPlatform::FACEBOOK,
            StatsPeriod::WEEK,
            '{{clubName}} leads with {{value}} transfers this {{period}}!',
        );

        $this->assertSame(StatCategory::MOST_TRANSFERS, $template->getCategory());
        $this->assertSame(SocialPlatform::FACEBOOK, $template->getPlatform());
        $this->assertSame(StatsPeriod::WEEK, $template->getPeriod());
        $this->assertSame('{{clubName}} leads with {{value}} transfers this {{period}}!', $template->getBodyTemplate());
        $this->assertTrue($template->isActive());
        $this->assertEqualsWithDelta(time(), $template->getCreatedAt()->getTimestamp(), 2);
        $this->assertEqualsWithDelta(time(), $template->getUpdatedAt()->getTimestamp(), 2);
    }

    public function testSettersUpdateFieldsAndBumpUpdatedAt(): void
    {
        $template = new SocialPostTemplate(StatCategory::MOST_TROPHIES, SocialPlatform::TWITTER, StatsPeriod::ALL);
        $originalUpdatedAt = $template->getUpdatedAt();

        usleep(1100000); // ensure the next DateTimeImmutable() second differs
        $template->setBodyTemplate('{{clubName}} has won {{value}} titles!');
        $template->setPeriod(StatsPeriod::SEASON);
        $template->setIsActive(false);

        $this->assertSame('{{clubName}} has won {{value}} titles!', $template->getBodyTemplate());
        $this->assertSame(StatsPeriod::SEASON, $template->getPeriod());
        $this->assertFalse($template->isActive());
        $this->assertGreaterThan($originalUpdatedAt, $template->getUpdatedAt());
    }

    public function testConstructorWorksWithNoArguments(): void
    {
        // EasyAdmin's AbstractCrudController instantiates the entity with zero
        // arguments (`new SocialPostTemplate()`) to build the blank "New" form —
        // discovered via manual verification of the admin CRUD screen returning
        // a 500 (ArgumentCountError) before all constructor params had defaults.
        $template = new SocialPostTemplate();

        $this->assertSame(StatCategory::MOST_TRANSFERS, $template->getCategory());
        $this->assertSame(SocialPlatform::FACEBOOK, $template->getPlatform());
        $this->assertSame(StatsPeriod::ALL, $template->getPeriod());
        $this->assertSame('', $template->getBodyTemplate());
    }
}
