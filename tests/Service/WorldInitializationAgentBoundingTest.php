<?php

namespace App\Tests\Service;

use App\Entity\Agent;
use App\Service\WorldInitializationService;
use PHPUnit\Framework\TestCase;

class WorldInitializationAgentBoundingTest extends TestCase
{
    private function service(): WorldInitializationService
    {
        // selectBoundedAgentPool is pure — no constructor deps needed.
        return (new \ReflectionClass(WorldInitializationService::class))->newInstanceWithoutConstructor();
    }

    /** @return Agent[] */
    private function agents(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = new Agent("Agent $i");
        }
        return $out;
    }

    public function testTargetIsPlayersDividedByRatio(): void
    {
        $subset = $this->service()->selectBoundedAgentPool($this->agents(300), 2400, 12);
        $this->assertCount(200, $subset); // ceil(2400/12)
    }

    public function testNeverExceedsPoolSize(): void
    {
        $subset = $this->service()->selectBoundedAgentPool($this->agents(100), 2400, 12);
        $this->assertCount(100, $subset); // ceil(2400/12)=200 capped at 100 available
    }

    public function testSmallEstimateRoundsUp(): void
    {
        $subset = $this->service()->selectBoundedAgentPool($this->agents(50), 28, 12);
        $this->assertCount(3, $subset); // ceil(28/12)=3
    }

    public function testEmptyPoolReturnsEmpty(): void
    {
        $this->assertSame([], $this->service()->selectBoundedAgentPool([], 2400, 12));
    }

    public function testNonPositiveEstimateReturnsPoolUnchanged(): void
    {
        $agents = $this->agents(5);
        $this->assertCount(5, $this->service()->selectBoundedAgentPool($agents, 0, 12));
    }

    public function testSubsetElementsAreFromThePool(): void
    {
        $agents = $this->agents(50);
        $subset = $this->service()->selectBoundedAgentPool($agents, 28, 12);
        foreach ($subset as $a) {
            $this->assertContains($a, $agents);
        }
    }
}
