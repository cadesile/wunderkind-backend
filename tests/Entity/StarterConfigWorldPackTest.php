<?php

namespace App\Tests\Entity;

use App\Entity\StarterConfig;
use PHPUnit\Framework\TestCase;

class StarterConfigWorldPackTest extends TestCase
{
    public function testWorldPackPlayersPerAgentDefaultsTo12(): void
    {
        $this->assertSame(12, (new StarterConfig())->getWorldPackPlayersPerAgent());
        $this->assertSame(12, StarterConfig::defaults()->getWorldPackPlayersPerAgent());
    }

    public function testWorldPackPlayersPerAgentIsConfigurable(): void
    {
        $cfg = new StarterConfig();
        $cfg->setWorldPackPlayersPerAgent(8);
        $this->assertSame(8, $cfg->getWorldPackPlayersPerAgent());
    }
}
