<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use PHPUnit\Framework\TestCase;

class GameEventTemplateRepositoryTest extends TestCase
{
    public function testGameEventTemplateConstructor(): void
    {
        $template = new GameEventTemplate(
            'player_homesick',
            EventCategory::PLAYER,
            'Homesickness',
            '{player} is feeling homesick.',
            [['target' => 'player.morale', 'delta' => -8]],
            3,
        );

        $this->assertSame('player_homesick', $template->getSlug());
        $this->assertSame(EventCategory::PLAYER, $template->getCategory());
        $this->assertSame('Homesickness', $template->getTitle());
        $this->assertSame(3, $template->getWeight());
        $this->assertCount(1, $template->getImpacts());
        $this->assertNotNull($template->getId());
    }

    public function testWeightIsClampedToZeroMinimum(): void
    {
        $template = new GameEventTemplate(
            'test_event',
            EventCategory::STAFF,
            'Test',
            'Test body.',
        );

        $template->setWeight(-5);
        $this->assertSame(0, $template->getWeight());
    }

    public function testDefaultWeight(): void
    {
        $template = new GameEventTemplate(
            'test_event',
            EventCategory::FINANCE,
            'Test',
            'Test body.',
        );

        $this->assertSame(1, $template->getWeight());
    }
}
