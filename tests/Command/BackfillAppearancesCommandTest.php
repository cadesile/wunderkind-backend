<?php
namespace App\Tests\Command;

use App\Command\BackfillAppearancesCommand;
use PHPUnit\Framework\TestCase;

class BackfillAppearancesCommandTest extends TestCase
{
    public function testCommandNameIsConfigured(): void
    {
        $ref = new \ReflectionClass(BackfillAppearancesCommand::class);
        $attr = $ref->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);
        $this->assertNotEmpty($attr);
        $this->assertSame('app:backfill-appearances', $attr[0]->newInstance()->name);
    }
}
