<?php

namespace App\Tests\Dto\Leaderboard;

use App\Dto\Leaderboard\LeaderboardItemDto;
use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class LeaderboardItemDtoTest extends TestCase
{
    public function testFromEntryCarriesTheClubsKitAndBadgeConfig(): void
    {
        $user = $this->createStub(User::class);
        $club = new Club('Toro SD', $user);
        $home = ['kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee'];
        $away = ['kit' => 'hoops', 'primary' => '#000000', 'secondary' => '#ffffff'];
        $badge = ['badgeShape' => 'shield', 'initials' => 'TSD'];
        $club->setHomeKitConfig($home);
        $club->setAwayKitConfig($away);
        $club->setBadgeConfig($badge);

        $entry = $this->createStub(LeaderboardEntry::class);
        $entry->method('getClub')->willReturn($club);
        $entry->method('getScore')->willReturn(100);
        $entry->method('getDisplayLabel')->willReturn(null);

        $dto = LeaderboardItemDto::fromEntry($entry, 1);

        $this->assertSame($home, $dto->homeKitConfig);
        $this->assertSame($away, $dto->awayKitConfig);
        $this->assertSame($badge, $dto->badgeConfig);
        $this->assertSame($home, $dto->toArray()['homeKitConfig']);
        $this->assertSame($badge, $dto->toArray()['badgeConfig']);
    }

    public function testFromEntryHandlesAClubWithNoKitIdentitySetYet(): void
    {
        $user = $this->createStub(User::class);
        $club = new Club('Undressed FC', $user);

        $entry = $this->createStub(LeaderboardEntry::class);
        $entry->method('getClub')->willReturn($club);
        $entry->method('getScore')->willReturn(50);
        $entry->method('getDisplayLabel')->willReturn(null);

        $dto = LeaderboardItemDto::fromEntry($entry, 2);

        $this->assertNull($dto->homeKitConfig);
        $this->assertNull($dto->awayKitConfig);
        $this->assertNull($dto->badgeConfig);
    }
}
