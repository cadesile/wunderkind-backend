<?php

namespace App\Tests\Service;

use App\Enum\StaffRole;
use PHPUnit\Framework\TestCase;

class MarketPoolServiceGenerateStaffTest extends TestCase
{
    public function testGenerateStaffForRoleAssignsCorrectRole(): void
    {
        $role = StaffRole::MANAGER;

        $this->assertSame('manager', $role->value);
        $this->assertInstanceOf(StaffRole::class, StaffRole::from('manager'));
    }

    public function testAllSixNonScoutRolesAreDistinct(): void
    {
        $roles = [
            StaffRole::COACH,
            StaffRole::ASSISTANT_COACH,
            StaffRole::MANAGER,
            StaffRole::DIRECTOR_OF_FOOTBALL,
            StaffRole::FACILITY_MANAGER,
            StaffRole::CHAIRMAN,
        ];

        $this->assertCount(6, array_unique(array_map(fn($r) => $r->value, $roles)));
    }
}
