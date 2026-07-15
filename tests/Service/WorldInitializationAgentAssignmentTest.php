<?php

namespace App\Tests\Service;

use App\Entity\Agent;
use App\Entity\Player;
use App\Service\WorldInitializationService;
use PHPUnit\Framework\TestCase;

class WorldInitializationAgentAssignmentTest extends TestCase
{
    private function service(): WorldInitializationService
    {
        // assignAgents is a pure mapper over its arguments — no constructor deps needed.
        return (new \ReflectionClass(WorldInitializationService::class))->newInstanceWithoutConstructor();
    }

    public function testEveryPlayerGetsAnAgentAndAgentsAreSharedWhenFewerThanPlayers(): void
    {
        $agents  = [new Agent('Agent A'), new Agent('Agent B')];
        $players = [
            new Player('P', '1'), new Player('P', '2'), new Player('P', '3'),
            new Player('P', '4'), new Player('P', '5'),
        ];

        $this->service()->assignAgents($players, $agents);

        foreach ($players as $p) {
            $this->assertNotNull($p->getAgent(), 'every world-pack player should have an agent');
        }

        // Pigeonhole: 5 players / 2 agents → at least one agent represents 2+ players (many-to-one).
        $uniqueAgentIds = array_unique(array_map(
            static fn(Player $p) => (string) $p->getAgent()->getId(),
            $players,
        ));
        $this->assertLessThan(count($players), count($uniqueAgentIds), 'at least one agent must be shared');
    }

    public function testReassignsOverAnyExistingAgent(): void
    {
        $old   = new Agent('Old Agent');
        $pool  = new Agent('Pool Agent');
        $player = new Player('P', '1');
        $player->setAgent($old);

        $this->service()->assignAgents([$player], [$pool]);

        $this->assertSame($pool, $player->getAgent(), 'reassign-all should replace the existing agent from the pool');
    }

    public function testNoOpWhenAgentPoolIsEmpty(): void
    {
        $player = new Player('P', '1');
        $this->service()->assignAgents([$player], []);
        $this->assertNull($player->getAgent());
    }

    public function testBuildPlayerSnapshotNestsAgentUsingSharedShape(): void
    {
        $agent = new Agent('Jorge Mendes');
        $agent->setCommissionRate('10.00');
        $player = new Player('A', 'B');
        $player->setAgent($agent);

        $snap = $this->service()->buildPlayerSnapshot($player);

        $this->assertArrayHasKey('agent', $snap);
        $this->assertSame($agent->toSnapshotArray(), $snap['agent']);
    }

    public function testBuildPlayerSnapshotAgentIsNullWhenPlayerHasNone(): void
    {
        $snap = $this->service()->buildPlayerSnapshot(new Player('A', 'B'));

        $this->assertArrayHasKey('agent', $snap);
        $this->assertNull($snap['agent']);
    }
}
