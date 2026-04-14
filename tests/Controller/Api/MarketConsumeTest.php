<?php

namespace App\Tests\Controller\Api;

use App\Dto\ConsumeRequest;
use PHPUnit\Framework\TestCase;

class MarketConsumeTest extends TestCase
{
    public function testConsumeRequestDto(): void
    {
        $dto = new ConsumeRequest(
            playerIds: ['550e8400-e29b-41d4-a716-446655440000'],
            staffIds:  ['6ba7b810-9dad-11d1-80b4-00c04fd430c8'],
            scoutIds:  [],
        );

        $this->assertSame(['550e8400-e29b-41d4-a716-446655440000'], $dto->playerIds);
        $this->assertSame(['6ba7b810-9dad-11d1-80b4-00c04fd430c8'], $dto->staffIds);
        $this->assertSame([], $dto->scoutIds);
    }

    public function testConsumeMethodExistsOnController(): void
    {
        $ref = new \ReflectionClass(\App\Controller\Api\MarketController::class);
        $this->assertTrue($ref->hasMethod('consume'));
    }
}
