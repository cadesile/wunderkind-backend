<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Player;
use App\Entity\Staff;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use PHPUnit\Framework\TestCase;

class PoolEntityTest extends TestCase
{
    public function testPlayerHasNoClubMethods(): void
    {
        $this->assertFalse(method_exists(Player::class, 'getClub'));
        $this->assertFalse(method_exists(Player::class, 'setClub'));
        $this->assertFalse(method_exists(Player::class, 'isInMarketPool'));
        $this->assertFalse(method_exists(Player::class, 'isAssigned'));
        $this->assertFalse(method_exists(Player::class, 'isAgeOutWarningIssued'));
        $this->assertFalse(method_exists(Player::class, 'isForcedSaleExecuted'));
        $this->assertFalse(method_exists(Player::class, 'getForcedSaleWeek'));
    }

    public function testPlayerCanBeConstructedWithoutClub(): void
    {
        $player = new Player(
            firstName: 'John',
            lastName: 'Doe',
            nationality: 'English',
            position: PlayerPosition::MIDFIELDER,
            recruitmentSource: RecruitmentSource::YOUTH_INTAKE,
            potential: 70,
            currentAbility: 50,
        );
        $this->assertSame('John', $player->getFirstName());
    }

    public function testStaffHasNoClubMethods(): void
    {
        $this->assertFalse(method_exists(Staff::class, 'getClub'));
        $this->assertFalse(method_exists(Staff::class, 'setClub'));
        $this->assertFalse(method_exists(Staff::class, 'isInMarketPool'));
        $this->assertFalse(method_exists(Staff::class, 'isAssigned'));
    }

    public function testStaffCanBeConstructedWithoutClub(): void
    {
        $staff = new Staff(firstName: 'Jane', lastName: 'Smith', role: StaffRole::COACH);
        $this->assertSame('Jane', $staff->getFirstName());
    }
}
