<?php

namespace App\Tests\Entity;

use App\Entity\Agent;
use PHPUnit\Framework\TestCase;

class AgentSnapshotTest extends TestCase
{
    public function testToSnapshotArrayMatchesScoutSearchShape(): void
    {
        $agent = new Agent('Jorge Mendes');
        $agent->setCommissionRate('12.50');

        $snap = $agent->toSnapshotArray();

        // Exactly the shape ScoutSearchController::serializePlayer nests today.
        $this->assertSame(['id', 'name', 'commissionRate'], array_keys($snap));
        $this->assertSame($agent->getId()->toRfc4122(), $snap['id']);
        $this->assertSame('Jorge Mendes', $snap['name']);
        $this->assertSame('12.50', $snap['commissionRate']);
    }
}
