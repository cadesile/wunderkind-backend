<?php

namespace App\Tests\Entity;

use App\Entity\PoolConfig;
use PHPUnit\Framework\TestCase;

class PoolConfigStaffTargetsTest extends TestCase
{
    public function testDefaultStaffRoleTargets(): void
    {
        $cfg = new PoolConfig();
        $this->assertSame(10,  $cfg->getCoachPoolTarget());
        $this->assertSame(5,   $cfg->getManagerPoolTarget());
        $this->assertSame(2,   $cfg->getDirectorOfFootballPoolTarget());
        $this->assertSame(3,   $cfg->getFacilityManagerPoolTarget());
        $this->assertSame(2,   $cfg->getChairmanPoolTarget());
    }

    public function testSetters(): void
    {
        $cfg = new PoolConfig();
        $cfg->setManagerPoolTarget(25);
        $cfg->setDirectorOfFootballPoolTarget(10);
        $cfg->setFacilityManagerPoolTarget(15);
        $cfg->setChairmanPoolTarget(5);

        $this->assertSame(25, $cfg->getManagerPoolTarget());
        $this->assertSame(10, $cfg->getDirectorOfFootballPoolTarget());
        $this->assertSame(15, $cfg->getFacilityManagerPoolTarget());
        $this->assertSame(5,  $cfg->getChairmanPoolTarget());
    }
}
