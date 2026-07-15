<?php

namespace App\Tests\Entity;

use App\Entity\PoolConfig;
use PHPUnit\Framework\TestCase;

class PoolConfigAgentTargetTest extends TestCase
{
    public function testAgentPoolTargetDefaultsTo100(): void
    {
        $cfg = new PoolConfig();
        $this->assertSame(100, $cfg->getAgentPoolTarget());
    }

    public function testAgentPoolTargetIsConfigurable(): void
    {
        $cfg = new PoolConfig();
        $cfg->setAgentPoolTarget(250);
        $this->assertSame(250, $cfg->getAgentPoolTarget());
    }
}
