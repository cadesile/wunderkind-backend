<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\NpcClubCrudController;
use App\Entity\NpcClub;
use PHPUnit\Framework\TestCase;

class NpcClubCrudControllerTest extends TestCase
{
    public function testEntityFqcn(): void
    {
        $this->assertSame(NpcClub::class, NpcClubCrudController::getEntityFqcn());
    }
}
