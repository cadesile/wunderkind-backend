<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\SocialPostTemplateCrudController;
use App\Entity\SocialPostTemplate;
use PHPUnit\Framework\TestCase;

class SocialPostTemplateCrudControllerTest extends TestCase
{
    public function testEntityFqcn(): void
    {
        $this->assertSame(SocialPostTemplate::class, SocialPostTemplateCrudController::getEntityFqcn());
    }
}
