# Club Init Pool Prewarm Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Guarantee same-nationality players, staff, and scouts are available in the pool before `StarterPackService::initialize()` runs its pool queries.

**Architecture:** Inject `MarketPoolService` into `StarterPackService` and call a new private `prewarmPoolForClub()` method at the top of `initialize()`. It generates `starterPlayerCount * 2` players (ability-range buffer) and exact counts of staff/scouts for the club's nationality, dropping them into the shared pool before the existing queries run.

**Tech Stack:** PHP 8.4, Symfony, Doctrine ORM, PHPUnit

## Global Constraints

- All PHP must run inside the Lando container (`lando php ...`)
- No changes to `InitializeController`, `ClubController`, repositories, DTOs, or frontend contracts
- Follow existing constructor injection patterns (readonly properties)
- Branch: `feat/club-init-pool-prewarm`

---

### Task 1: Inject `MarketPoolService` and add `prewarmPoolForClub()`

**Files:**
- Modify: `src/Service/StarterPackService.php`
- Test: `tests/Service/StarterPackServicePrewarmTest.php`

**Interfaces:**
- Consumes: `MarketPoolService::generatePlayers(int, RecruitmentSource, ?string): Player[]`, `MarketPoolService::generateStaffForRole(StaffRole, int, ?string): Staff[]`, `MarketPoolService::generateScouts(int, ?string): Scout[]`
- Produces: `StarterPackService::initialize(Club): array` — unchanged signature, prewarm runs internally

- [ ] **Step 1: Create the branch**

```bash
git checkout -b feat/club-init-pool-prewarm
```

- [ ] **Step 2: Write the failing test**

Create `tests/Service/StarterPackServicePrewarmTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\StarterConfig;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\MarketPoolService;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StarterPackServicePrewarmTest extends TestCase
{
    public function testPrewarmGeneratesNationalityBufferBeforePoolQueries(): void
    {
        // StarterConfig: 5 players, 1 coach, 1 manager, 1 chairman, 0 DOF, 0 facility, 1 scout
        $config = $this->createMock(StarterConfig::class);
        $config->method('getStarterPlayerCount')->willReturn(5);
        $config->method('getStarterCoachCount')->willReturn(1);
        $config->method('getStarterManagerCount')->willReturn(1);
        $config->method('getStarterChairmanCount')->willReturn(1);
        $config->method('getStarterDirectorOfFootballCount')->willReturn(0);
        $config->method('getStarterFacilityManagerCount')->willReturn(0);
        $config->method('getStarterScoutCount')->willReturn(1);

        $starterConfigRepo = $this->createMock(StarterConfigRepository::class);
        $starterConfigRepo->method('getConfig')->willReturn($config);

        // Club is EN → nationality is 'English'
        $club = $this->createMock(Club::class);
        $club->method('getCountry')->willReturn('EN');
        $club->method('getCurrentLeague')->willReturn(null);
        $club->method('isStarterInitialized')->willReturn(false);
        $club->method('getReputation')->willReturn(0);

        $marketPool = $this->createMock(MarketPoolService::class);

        // Players: 5 * 2 = 10, nationality English
        $marketPool->expects($this->once())
            ->method('generatePlayers')
            ->with(10, RecruitmentSource::YOUTH_INTAKE, 'English');

        // Staff roles with count > 0: COACH, MANAGER, CHAIRMAN
        $marketPool->expects($this->exactly(3))
            ->method('generateStaffForRole')
            ->willReturnCallback(function (StaffRole $role, int $count, string $nat) {
                $this->assertSame('English', $nat);
                $this->assertGreaterThan(0, $count);
                $this->assertContains($role, [StaffRole::COACH, StaffRole::MANAGER, StaffRole::CHAIRMAN]);
                return [];
            });

        // Scouts: exact count 1
        $marketPool->expects($this->once())
            ->method('generateScouts')
            ->with(1, 'English');

        // Remaining dependencies — stub out so initialize() doesn't blow up
        $playerRepo = $this->createMock(PlayerRepository::class);
        $playerRepo->method('findForWorldInitByPositionAndNationality')->willReturn([]);
        $playerRepo->method('findForeignForWorldInitByPosition')->willReturn([]);

        $staffRepo = $this->createMock(StaffRepository::class);
        $staffRepo->method('findInPoolByRoleRandom')->willReturn([]);

        $scoutRepo = $this->createMock(ScoutRepository::class);
        $scoutRepo->method('findInPool')->willReturn([]);

        $poolConfigRepo = $this->createMock(PoolConfigRepository::class);
        $poolConfig = $this->createMock(\App\Entity\PoolConfig::class);
        $poolConfig->method('getPositionWeightGk')->willReturn(1);
        $poolConfig->method('getPositionWeightDef')->willReturn(4);
        $poolConfig->method('getPositionWeightMid')->willReturn(4);
        $poolConfig->method('getPositionWeightAtt')->willReturn(3);
        $poolConfigRepo->method('getConfig')->willReturn($poolConfig);

        $worldInit = $this->createMock(WorldInitializationService::class);
        $worldInit->method('distributeByPosition')->willReturn([
            'GOALKEEPER' => 1,
            'DEFENDER'   => 2,
            'MIDFIELDER' => 1,
            'ATTACKER'   => 1,
        ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');

        $service = new StarterPackService(
            $playerRepo,
            $staffRepo,
            $scoutRepo,
            $starterConfigRepo,
            $poolConfigRepo,
            $worldInit,
            $em,
            $marketPool,
        );

        // initialize() returns the payload array; we only care that prewarm was called
        $result = $service->initialize($club);
        $this->assertArrayHasKey('players', $result);
        $this->assertArrayHasKey('staff', $result);
        $this->assertArrayHasKey('scouts', $result);
    }
}
```

- [ ] **Step 3: Run the test — confirm it fails**

```bash
lando php vendor/bin/phpunit tests/Service/StarterPackServicePrewarmTest.php --no-coverage
```

Expected: FAIL — constructor argument count mismatch (MarketPoolService not yet injected).

- [ ] **Step 4: Modify `StarterPackService`**

Replace the constructor and add the private method. Full updated file:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Entity\StarterConfig;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\ClubInitializationService;
use Doctrine\ORM\EntityManagerInterface;

class StarterPackService
{
    public function __construct(
        private readonly PlayerRepository           $playerRepository,
        private readonly StaffRepository            $staffRepository,
        private readonly ScoutRepository            $scoutRepository,
        private readonly StarterConfigRepository    $starterConfigRepository,
        private readonly PoolConfigRepository       $poolConfigRepository,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly EntityManagerInterface     $em,
        private readonly MarketPoolService          $marketPoolService,
    ) {}

    /**
     * Assembles the AMP starter squad from the pool, assigns players/staff to the club,
     * sets starterInitializedAt, flushes, and returns the starter payload array.
     */
    public function initialize(Club $club): array
    {
        $starterConfig  = $this->starterConfigRepository->getConfig();
        $leagueRanges   = $starterConfig->getLeagueAbilityRanges();
        $country        = $club->getCountry();
        $ampLeagueTier  = $club->getCurrentLeague()?->getTier() ?? 8;
        $ampRangeRaw    = $leagueRanges[$country][(string) $ampLeagueTier]
            ?? WorldInitializationService::ABILITY_RANGES[$ampLeagueTier]
            ?? ['min' => 5, 'max' => 35];
        $ampRange       = ['min' => (int) $ampRangeRaw['min'], 'max' => (int) $ampRangeRaw['max']];
        $ampNationality = ClubInitializationService::countryToNationality($country) ?? $country;

        $this->prewarmPoolForClub($club, $starterConfig, $ampNationality);

        $poolConfig = $this->poolConfigRepository->getConfig();
        $posCounts  = $this->worldInitializationService->distributeByPosition(
            $starterConfig->getStarterPlayerCount(),
            $poolConfig
        );
        $ampPlayers = [];

        foreach ($posCounts as $posValue => $count) {
            $position   = PlayerPosition::from($posValue);
            $posPlayers = $this->playerRepository->findForWorldInitByPositionAndNationality(
                $ampRange['min'], $ampRange['max'], $position, $ampNationality, $count
            );
            if (count($posPlayers) < $count) {
                $deficit    = $count - count($posPlayers);
                $extra      = $this->playerRepository->findForeignForWorldInitByPosition(
                    $ampRange['min'], $ampRange['max'], '__none__', $position, $deficit
                );
                $posPlayers = array_merge($posPlayers, $extra);
            }
            $ampPlayers = array_merge($ampPlayers, $posPlayers);
        }
        $ampPlayers = array_values(array_unique($ampPlayers, SORT_REGULAR));

        $ampStaff = array_merge(
            $this->fillStaffRole(StaffRole::MANAGER,              $starterConfig->getStarterManagerCount(),            $ampNationality),
            $this->fillStaffRole(StaffRole::COACH,                $starterConfig->getStarterCoachCount(),              $ampNationality),
            $this->fillStaffRole(StaffRole::DIRECTOR_OF_FOOTBALL, $starterConfig->getStarterDirectorOfFootballCount(), $ampNationality),
            $this->fillStaffRole(StaffRole::FACILITY_MANAGER,     $starterConfig->getStarterFacilityManagerCount(),    $ampNationality),
            $this->fillStaffRole(StaffRole::CHAIRMAN,             $starterConfig->getStarterChairmanCount(),           $ampNationality),
        );

        // Scouts are not consumed from the pool (no assignedAt guard) — this mirrors
        // the pre-existing behaviour in WorldInitializationService::initialize().
        $ampScouts = $this->scoutRepo->findInPool($starterConfig->getStarterScoutCount(), nationality: $ampNationality);
        if (count($ampScouts) < $starterConfig->getStarterScoutCount()) {
            $deficit   = $starterConfig->getStarterScoutCount() - count($ampScouts);
            $ampScouts = array_merge($ampScouts, $this->scoutRepo->findInPool($deficit));
        }
        $ampScouts = array_values(array_unique($ampScouts, SORT_REGULAR));

        foreach ($ampPlayers as $p) { $p->setClub($club); }
        foreach ($ampStaff   as $s) { $s->setClub($club); }

        $club->setStarterInitializedAt(new \DateTimeImmutable());
        $this->em->flush();

        return [
            'players' => array_map(
                fn(Player $p) => $this->worldInitializationService->buildPlayerSnapshot($p),
                $ampPlayers
            ),
            'staff'   => array_map(
                fn(Staff $s) => $this->worldInitializationService->buildStaffSnapshot($s),
                $ampStaff
            ),
            'scouts'  => array_map(
                fn(Scout $s) => $this->worldInitializationService->buildScoutSnapshot($s),
                $ampScouts
            ),
        ];
    }

    private function prewarmPoolForClub(Club $club, StarterConfig $config, string $nationality): void
    {
        $this->marketPoolService->generatePlayers(
            $config->getStarterPlayerCount() * 2,
            RecruitmentSource::YOUTH_INTAKE,
            $nationality,
        );

        $staffRoles = [
            [StaffRole::MANAGER,              $config->getStarterManagerCount()],
            [StaffRole::COACH,                $config->getStarterCoachCount()],
            [StaffRole::DIRECTOR_OF_FOOTBALL, $config->getStarterDirectorOfFootballCount()],
            [StaffRole::FACILITY_MANAGER,     $config->getStarterFacilityManagerCount()],
            [StaffRole::CHAIRMAN,             $config->getStarterChairmanCount()],
        ];

        foreach ($staffRoles as [$role, $count]) {
            if ($count > 0) {
                $this->marketPoolService->generateStaffForRole($role, $count, $nationality);
            }
        }

        $this->marketPoolService->generateScouts($config->getStarterScoutCount(), $nationality);
    }

    private function fillStaffRole(StaffRole $role, int $limit, string $nationality): array
    {
        $results = $this->staffRepository->findInPoolByRoleRandom($role, $limit, $nationality);
        if (count($results) < $limit) {
            $deficit = $limit - count($results);
            $results = array_merge(
                $results,
                $this->staffRepository->findInPoolByRoleRandom($role, $deficit)
            );
        }
        return $results;
    }
}
```

> **Note:** The scout query in `initialize()` above uses `$this->scoutRepo` (private field name from existing code). Verify the actual field name is `$this->scoutRepository` and update accordingly — do not change the logic, only match the field name in the existing file.

- [ ] **Step 5: Run the test — confirm it passes**

```bash
lando php vendor/bin/phpunit tests/Service/StarterPackServicePrewarmTest.php --no-coverage
```

Expected: PASS

- [ ] **Step 6: Run the full test suite — confirm no regressions**

```bash
lando php vendor/bin/phpunit --no-coverage
```

Expected: all previously passing tests still pass.

- [ ] **Step 7: Smoke-test in a running environment**

```bash
lando start
# Ensure pool is warm
lando php bin/console app:pool:warm EN
# Call the starter endpoint with a valid JWT for an EN club that hasn't been initialized
# e.g. using curl or the API browser at /api
```

Expected: `POST /api/initialize/starter` returns `ampStarter` payload where the majority of players have `nationality: "English"`.

- [ ] **Step 8: Commit**

```bash
git add src/Service/StarterPackService.php tests/Service/StarterPackServicePrewarmTest.php
git commit -m "feat: prewarm pool with club nationality before starter pack initialization"
```
