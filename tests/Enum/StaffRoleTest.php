<?php

namespace App\Tests\Enum;

use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use PHPUnit\Framework\TestCase;

class StaffRoleTest extends TestCase
{
    public function testNewRolesExist(): void
    {
        $this->assertSame('manager', StaffRole::MANAGER->value);
        $this->assertSame('director_of_football', StaffRole::DIRECTOR_OF_FOOTBALL->value);
        $this->assertSame('facility_manager', StaffRole::FACILITY_MANAGER->value);
    }

    public function testSeniorIntakeExists(): void
    {
        $source = RecruitmentSource::SENIOR_INTAKE;
        $this->assertSame('senior_intake', $source->value);
    }
}
