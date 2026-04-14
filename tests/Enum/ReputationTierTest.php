<?php

namespace App\Tests\Enum;

use App\Enum\ReputationTier;
use PHPUnit\Framework\TestCase;

class ReputationTierTest extends TestCase
{
    public function testCasesExist(): void
    {
        $this->assertSame('local',    ReputationTier::LOCAL->value);
        $this->assertSame('regional', ReputationTier::REGIONAL->value);
        $this->assertSame('national', ReputationTier::NATIONAL->value);
        $this->assertSame('elite',    ReputationTier::ELITE->value);
    }

    public function testTryFrom(): void
    {
        $this->assertSame(ReputationTier::LOCAL, ReputationTier::tryFrom('local'));
        $this->assertNull(ReputationTier::tryFrom('unknown'));
    }
}
