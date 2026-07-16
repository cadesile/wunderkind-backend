<?php

namespace App\Tests\Service;

use App\Entity\Agent;
use App\Entity\Club;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\Player;
use App\Entity\Staff;
use App\Entity\User;
use App\Enum\PlayerPosition;
use App\Enum\StaffRole;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end proof that a generated world pack (buildTierPack) associates agents
 * with NPC-club players and that one agent is shared across 2+ players
 * (many-to-one). Uses a synthetic country so nationality resolves to the raw
 * string (countryToNationality falls back to the country) and the seed is
 * self-contained.
 */
class WorldInitializationTierPackAgentTest extends KernelTestCase
{
    private const COUNTRY = 'zx'; // synthetic 2-letter code (country is a varchar(2) ISO code); no nationality mapping → falls back to 'zx'
    private const TIER    = 8; // tier 8 → ABILITY_RANGES fallback [10,25]

    private EntityManagerInterface $em;
    /** @var object[] entities to remove in tearDown (players/staff are deleted by buildTierPack itself) */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    public function testGeneratedTierPackNestsSharedAgentsOnPlayers(): void
    {
        $league = new League(self::COUNTRY, self::TIER, 'Agent Test League');
        $this->em->persist($league);
        $this->track($league);

        // Two NPC clubs so fixture generation has an even set to pair.
        foreach (['Alpha', 'Beta'] as $name) {
            $npc = new NpcClub($name . ' FC', self::COUNTRY, self::TIER, 20, '#111111', '#eeeeee', 1_000_000, []);
            $npc->setLeague($league);
            $this->em->persist($npc);
            $this->track($npc);
        }

        // Ample pool players (15 per position) with ability inside [10,25] and the
        // synthetic nationality, so per-club position draws never run short.
        foreach (PlayerPosition::cases() as $position) {
            for ($i = 0; $i < 15; $i++) {
                $p = new Player('Pool', $position->value . $i, new \DateTimeImmutable('-17 years'), self::COUNTRY, $position);
                $p->setCurrentAbility(17);
                $p->setPotential(25);
                $this->em->persist($p); // prePersist fills appearance
            }
        }

        // One of each staff role the tier pack draws.
        foreach ([StaffRole::MANAGER, StaffRole::COACH, StaffRole::CHAIRMAN] as $role) {
            $this->em->persist(new Staff('Staff', $role->value, $role));
        }

        // A LARGE agent pool (50) — the world pack must NOT spread players across all
        // of them; it should bound to ~players/worldPackPlayersPerAgent.
        for ($i = 0; $i < 50; $i++) {
            $agent = new Agent("Agent $i");
            $agent->setCommissionRate('10.00');
            $this->em->persist($agent);
            $this->track($agent);
        }

        $user = new User('agent-tierpack-test@example.com');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->track($user);
        $club = new Club('Human Club', $user); // no current league → not added to fixtures
        $this->em->persist($club);
        $this->track($club);

        $this->em->flush();

        /** @var WorldInitializationService $svc */
        $svc = self::getContainer()->get(WorldInitializationService::class);
        $pack = $svc->buildTierPack($club, self::COUNTRY, self::TIER);

        $this->assertNotEmpty($pack['clubs'], 'tier pack should contain NPC clubs');

        $agentIdsAcrossAllPlayers = [];
        $sawPopulatedClub = false;
        foreach ($pack['clubs'] as $clubSnap) {
            $this->assertNotEmpty($clubSnap['players'], 'each NPC club should have players');
            $sawPopulatedClub = true;
            foreach ($clubSnap['players'] as $playerSnap) {
                $this->assertArrayHasKey('agent', $playerSnap);
                $this->assertNotNull($playerSnap['agent'], 'every world-pack player should carry an agent');
                $this->assertSame(
                    ['id', 'name', 'commissionRate', 'reputation', 'experience', 'rating', 'nationality', 'dateOfBirth'],
                    array_keys($playerSnap['agent']),
                );
                $agentIdsAcrossAllPlayers[] = $playerSnap['agent']['id'];
            }
        }

        $this->assertTrue($sawPopulatedClub);

        $totalPlayers = count($agentIdsAcrossAllPlayers);
        $distinct     = count(array_unique($agentIdsAcrossAllPlayers));

        // Many-to-one: fewer distinct agents than players → agents are shared.
        $this->assertLessThan($totalPlayers, $distinct, 'agents must be shared across players');

        // Bounded: distinct agents track ~players / worldPackPlayersPerAgent (default 12),
        // NOT the 50-strong pool. Before the fix this would be ~min(50, totalPlayers).
        $ratio            = 12;
        $expectedMaxAgents = (int) ceil($totalPlayers / $ratio) + 1; // +1 slack (subset sized on estimate)
        $this->assertLessThanOrEqual(
            $expectedMaxAgents,
            $distinct,
            "distinct agents ($distinct) should be bounded to ~players/$ratio, not the whole pool of 50",
        );
    }

    private function track(object $entity): void
    {
        $this->cleanup[] = $entity;
    }
}
