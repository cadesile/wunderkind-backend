<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\LeagueCrudController;
use App\Entity\League;
use PHPUnit\Framework\TestCase;

class LeagueCrudControllerTest extends TestCase
{
    public function testEntityFqcn(): void
    {
        $this->assertSame(League::class, LeagueCrudController::getEntityFqcn());
    }
}
