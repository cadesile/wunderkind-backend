<?php

namespace App\Tests\Controller\Api;

use App\Controller\Api\LeagueController;
use App\Dto\ConcludeSeasonRequest;
use PHPUnit\Framework\TestCase;

class LeagueControllerTest extends TestCase
{
    public function testConcludeSeasonRequestDefaults(): void
    {
        $dto = new ConcludeSeasonRequest();

        $this->assertSame(false, $dto->promoted);
        $this->assertSame(false, $dto->relegated);
        $this->assertSame([],    $dto->pyramidSnapshot);
        $this->assertSame(0,     $dto->gamesPlayed);
    }

    public function testControllerExists(): void
    {
        $this->assertTrue(class_exists(LeagueController::class));
    }

    public function testConcludeSeasonMethodExists(): void
    {
        $this->assertTrue(method_exists(LeagueController::class, 'concludeSeason'));
    }

    public function testSeasonHistoryMethodExists(): void
    {
        $this->assertTrue(method_exists(LeagueController::class, 'seasonHistory'));
    }

    public function testSeasonHistoryDetailMethodExists(): void
    {
        $this->assertTrue(method_exists(LeagueController::class, 'seasonHistoryDetail'));
    }
}
