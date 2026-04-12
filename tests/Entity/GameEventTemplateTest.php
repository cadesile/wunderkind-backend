<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use PHPUnit\Framework\TestCase;

class GameEventTemplateTest extends TestCase
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

    public function testChainedEventsDefaultsToNull(): void
    {
        $template = new GameEventTemplate(
            'test_event',
            EventCategory::PLAYER,
            'Test',
            'Body.',
        );

        $this->assertNull($template->getChainedEvents());
        $this->assertSame([], $template->getChainedEventsArray());
    }

    public function testSetChainedEventsArray(): void
    {
        $template = new GameEventTemplate(
            'player-argument',
            EventCategory::NPC_INTERACTION,
            'Argument',
            'Two players argue.',
        );

        $links = [
            [
                'nextEventSlug' => 'player-fight',
                'boostMultiplier' => 4.0,
                'windowWeeks' => 4,
                'note' => 'Escalates to fight',
            ],
        ];

        $template->setChainedEventsArray($links);

        $this->assertSame($links, $template->getChainedEvents());
        $this->assertSame($links, $template->getChainedEventsArray());
    }

    public function testSetChainedEventsArrayEmptyResetsToNull(): void
    {
        $template = new GameEventTemplate(
            'test_event',
            EventCategory::PLAYER,
            'Test',
            'Body.',
        );
        $template->setChainedEventsArray([['nextEventSlug' => 'other', 'boostMultiplier' => 2.0, 'windowWeeks' => 2, 'note' => null]]);
        $template->setChainedEventsArray([]);

        $this->assertNull($template->getChainedEvents());
    }

    public function testGetChainedEventsJsonReturnsEmptyArrayStringWhenNull(): void
    {
        $template = new GameEventTemplate(
            'test_event',
            EventCategory::PLAYER,
            'Test',
            'Body.',
        );

        $this->assertSame('[]', $template->getChainedEventsJson());
    }

    public function testGetChainedEventsWithoutNotesReturnsNullWhenChainedEventsIsNull(): void
    {
        $template = new GameEventTemplate(
            'test_event',
            EventCategory::PLAYER,
            'Test',
            'Body.',
        );

        $this->assertNull($template->getChainedEventsWithoutNotes());
    }

    public function testGetChainedEventsWithoutNotesStripsNoteField(): void
    {
        $template = new GameEventTemplate(
            'player-argument',
            EventCategory::NPC_INTERACTION,
            'Argument',
            'Two players argue.',
        );

        $template->setChainedEventsArray([
            [
                'nextEventSlug'   => 'player-fight',
                'boostMultiplier' => 4.0,
                'windowWeeks'     => 4,
                'note'            => 'Admin note here',
            ],
        ]);

        $result = $template->getChainedEventsWithoutNotes();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('player-fight', $result[0]['nextEventSlug']);
        $this->assertSame(4.0, $result[0]['boostMultiplier']);
        $this->assertSame(4, $result[0]['windowWeeks']);
        $this->assertArrayNotHasKey('note', $result[0]);
    }
}
