<?php

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use App\Enum\StatCategory;
use PHPUnit\Framework\TestCase;

class GameConfigSocialPostingTest extends TestCase
{
    public function testLastPostedStatCategoryDefaultsToNull(): void
    {
        $config = new GameConfig();
        $this->assertNull($config->getLastPostedStatCategory());
    }

    public function testLastPostedStatCategoryCanBeSetAndRead(): void
    {
        $config = new GameConfig();
        $config->setLastPostedStatCategory(StatCategory::MOST_SEASONS);
        $this->assertSame(StatCategory::MOST_SEASONS, $config->getLastPostedStatCategory());
    }
}
