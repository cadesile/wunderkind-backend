<?php

namespace App\Tests\Dto;

use App\Dto\MatchResultDto;
use App\Dto\SyncRequest;
use PHPUnit\Framework\TestCase;

class MatchResultDtoTest extends TestCase
{
    public function testMatchResultDtoDefaults(): void
    {
        $dto = new MatchResultDto();
        $this->assertSame('', $dto->opponentClubId);
        $this->assertSame(0,  $dto->goalsFor);
        $this->assertSame(0,  $dto->goalsAgainst);
        $this->assertSame(1,  $dto->week);
    }

    public function testSyncRequestMatchResultsDefaultsToEmpty(): void
    {
        $request = new SyncRequest();
        $this->assertSame([], $request->matchResults);
    }

    public function testSyncRequestSetMatchResultsFromArray(): void
    {
        $request = new SyncRequest();
        $request->setMatchResults([
            ['opponentClubId' => 'abc', 'goalsFor' => 2, 'goalsAgainst' => 1, 'week' => 5],
        ]);

        $this->assertCount(1, $request->matchResults);
        $this->assertInstanceOf(MatchResultDto::class, $request->matchResults[0]);
        $this->assertSame('abc', $request->matchResults[0]->opponentClubId);
        $this->assertSame(2,     $request->matchResults[0]->goalsFor);
        $this->assertSame(1,     $request->matchResults[0]->goalsAgainst);
        $this->assertSame(5,     $request->matchResults[0]->week);
    }
}
