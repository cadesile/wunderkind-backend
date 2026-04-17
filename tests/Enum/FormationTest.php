<?php

namespace App\Tests\Enum;

use App\Enum\Formation;
use PHPUnit\Framework\TestCase;

class FormationTest extends TestCase
{
    public function testCasesExist(): void
    {
        $this->assertSame('4-4-2',   Formation::F_442->value);
        $this->assertSame('4-3-3',   Formation::F_433->value);
        $this->assertSame('3-5-2',   Formation::F_352->value);
        $this->assertSame('5-4-1',   Formation::F_541->value);
        $this->assertSame('4-2-3-1', Formation::F_4231->value);
    }

    public function testTryFrom(): void
    {
        $this->assertSame(Formation::F_442, Formation::tryFrom('4-4-2'));
        $this->assertNull(Formation::tryFrom('unknown'));
    }
}
