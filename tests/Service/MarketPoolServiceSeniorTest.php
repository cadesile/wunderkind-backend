<?php

namespace App\Tests\Service;

use App\Service\MarketPoolService;
use PHPUnit\Framework\TestCase;

class MarketPoolServiceSeniorTest extends TestCase
{
    public function testGenerateSeniorPlayersMethodExists(): void
    {
        $ref = new \ReflectionClass(MarketPoolService::class);
        $this->assertTrue($ref->hasMethod('generateSeniorPlayers'));
        $method = $ref->getMethod('generateSeniorPlayers');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('count', $params[0]->getName());
    }
}
