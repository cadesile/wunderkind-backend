<?php

namespace App\Tests\Entity;

use App\Entity\PoolConfig;
use PHPUnit\Framework\TestCase;

class PoolConfigSeniorFieldsTest extends TestCase
{
    public function testSeniorFieldDefaults(): void
    {
        $config = new PoolConfig();

        $this->assertSame(17, $config->getSeniorPlayerAgeMin());
        $this->assertSame(35, $config->getSeniorPlayerAgeMax());
        $this->assertSame(20, $config->getSeniorPlayerAbilityMin());
        $this->assertSame(90, $config->getSeniorPlayerAbilityMax());
        $this->assertSame(200, $config->getSeniorPlayerPoolTarget());
    }

    public function testSeniorFieldSetters(): void
    {
        $config = new PoolConfig();
        $config->setSeniorPlayerAgeMin(18)->setSeniorPlayerAgeMax(32)
               ->setSeniorPlayerAbilityMin(30)->setSeniorPlayerAbilityMax(80)
               ->setSeniorPlayerPoolTarget(150);

        $this->assertSame(18, $config->getSeniorPlayerAgeMin());
        $this->assertSame(32, $config->getSeniorPlayerAgeMax());
        $this->assertSame(30, $config->getSeniorPlayerAbilityMin());
        $this->assertSame(80, $config->getSeniorPlayerAbilityMax());
        $this->assertSame(150, $config->getSeniorPlayerPoolTarget());
    }
}
