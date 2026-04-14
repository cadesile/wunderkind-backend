<?php

namespace App\Tests\Repository;

use App\Repository\PlayerRepository;
use PHPUnit\Framework\TestCase;

class PlayerRepositoryCountSeniorTest extends TestCase
{
    public function testCountSeniorInPoolMethodExists(): void
    {
        $ref = new \ReflectionClass(PlayerRepository::class);
        $this->assertTrue($ref->hasMethod('countSeniorInPool'));
        $method = $ref->getMethod('countSeniorInPool');
        $this->assertSame(0, $method->getNumberOfRequiredParameters());
    }
}
