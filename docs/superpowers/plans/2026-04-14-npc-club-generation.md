# NPC Club Generation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Spec A of the Club Sim expansion — NPC club persistence, senior player pool, and a consume endpoint — all backend only.

**Architecture:** Backend remains a producer, not a tracker. NPC clubs persist as pure metadata (no player FKs). Senior and youth players share a single pool. The frontend hard-deletes claimed entities via a new `POST /api/market/consume` endpoint.

**Tech Stack:** Symfony 8, Doctrine ORM, PostgreSQL 16, EasyAdmin 5, PHPUnit (stub-style), Lando (run all commands via `lando php bin/console` and `lando composer`)

---

## File Map

| Action | Path | Purpose |
|--------|------|---------|
| Modify | `src/Enum/StaffRole.php` | Add MANAGER, DIRECTOR_OF_FOOTBALL, FACILITY_MANAGER |
| Modify | `src/Enum/RecruitmentSource.php` | Add SENIOR_INTAKE |
| Modify | `src/Controller/Admin/StaffCrudController.php` | Add new StaffRole values to choices |
| Modify | `src/Controller/Admin/FacilityTemplateCrudController.php` | Add Stadium to category choices |
| Modify | `src/Entity/PoolConfig.php` | Add 5 senior player fields with getters/setters |
| Create | `src/Entity/NpcClub.php` | NPC club entity — pure metadata |
| Create | `src/Repository/NpcClubRepository.php` | Standard Doctrine repository |
| Create | `migrations/Version20260414000001.php` | Alter pool_config + create npc_club table |
| Modify | `src/Service/MarketPoolService.php` | Add generateSeniorPlayers(), extend replenishPool() + forceGeneratePool() |
| Modify | `src/Repository/PlayerRepository.php` | Add countSeniorInPool() |
| Create | `src/Service/NpcClubGenerationService.php` | generateClubs() with hybrid naming |
| Create | `src/Repository/FacilityTemplateRepository.php` | Add findActiveSlugs() helper (if not exists) |
| Modify | `src/Controller/Api/MarketController.php` | Add POST /api/market/consume |
| Create | `src/Dto/ConsumeRequest.php` | DTO for consume endpoint |
| Create | `src/Controller/Admin/NpcClubCrudController.php` | EasyAdmin CRUD for NpcClub |
| Modify | `src/Controller/Admin/DashboardController.php` | Add NPC club routes + menu items |
| Modify | `templates/admin/pool_config.html.twig` | Add senior player fields to config form |
| Create | `templates/admin/npc_clubs_content.html.twig` | Generate Clubs + Replenish Senior Pool actions |
| Create | `tests/Service/NpcClubGenerationServiceTest.php` | Unit tests |
| Create | `tests/Controller/Api/MarketConsumeTest.php` | API endpoint test |

---

## Task 1: StaffRole + RecruitmentSource enum additions

**Files:**
- Modify: `src/Enum/StaffRole.php`
- Modify: `src/Enum/RecruitmentSource.php`
- Modify: `src/Controller/Admin/StaffCrudController.php`

- [ ] **Step 1: Write failing test for new StaffRole values**

```php
// tests/Enum/StaffRoleTest.php
<?php

namespace App\Tests\Enum;

use App\Enum\StaffRole;
use PHPUnit\Framework\TestCase;

class StaffRoleTest extends TestCase
{
    public function testNewRolesExist(): void
    {
        $this->assertSame('manager', StaffRole::MANAGER->value);
        $this->assertSame('director_of_football', StaffRole::DIRECTOR_OF_FOOTBALL->value);
        $this->assertSame('facility_manager', StaffRole::FACILITY_MANAGER->value);
    }

    public function testSeniorIntakeExists(): void
    {
        $source = \App\Enum\RecruitmentSource::SENIOR_INTAKE;
        $this->assertSame('senior_intake', $source->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Enum/StaffRoleTest.php --testdox
```

Expected: FAIL — `MANAGER` not found on `StaffRole`

- [ ] **Step 3: Add new cases to StaffRole**

Replace `src/Enum/StaffRole.php` with:

```php
<?php

namespace App\Enum;

enum StaffRole: string
{
    case HEAD_COACH            = 'head_coach';
    case ASSISTANT_COACH       = 'assistant_coach';
    case SCOUT                 = 'scout';
    case FITNESS_COACH         = 'fitness_coach';
    case ANALYST               = 'analyst';
    case MANAGER               = 'manager';
    case DIRECTOR_OF_FOOTBALL  = 'director_of_football';
    case FACILITY_MANAGER      = 'facility_manager';
}
```

- [ ] **Step 4: Add SENIOR_INTAKE to RecruitmentSource**

Replace `src/Enum/RecruitmentSource.php` with:

```php
<?php

namespace App\Enum;

enum RecruitmentSource: string
{
    case SCOUTING_NETWORK = 'scouting_network';
    case COACHING_FIND    = 'coaching_find';
    case AGENT_OFFER      = 'agent_offer';
    case YOUTH_REQUEST    = 'youth_request';
    case YOUTH_INTAKE     = 'youth_intake';
    case SENIOR_INTAKE    = 'senior_intake';
}
```

- [ ] **Step 5: Add new roles to StaffCrudController choices**

In `src/Controller/Admin/StaffCrudController.php`, replace the `ChoiceField::new('role')` block:

```php
yield ChoiceField::new('role')
    ->setChoices([
        'Head Coach'            => StaffRole::HEAD_COACH,
        'Assistant Coach'       => StaffRole::ASSISTANT_COACH,
        'Scout'                 => StaffRole::SCOUT,
        'Fitness Coach'         => StaffRole::FITNESS_COACH,
        'Analyst'               => StaffRole::ANALYST,
        'Manager'               => StaffRole::MANAGER,
        'Director of Football'  => StaffRole::DIRECTOR_OF_FOOTBALL,
        'Facility Manager'      => StaffRole::FACILITY_MANAGER,
    ])
    ->renderAsBadges([
        StaffRole::HEAD_COACH->value             => 'danger',
        StaffRole::ASSISTANT_COACH->value        => 'warning',
        StaffRole::SCOUT->value                  => 'info',
        StaffRole::FITNESS_COACH->value          => 'success',
        StaffRole::ANALYST->value                => 'primary',
        StaffRole::MANAGER->value                => 'dark',
        StaffRole::DIRECTOR_OF_FOOTBALL->value   => 'secondary',
        StaffRole::FACILITY_MANAGER->value       => 'light',
    ]);
```

- [ ] **Step 6: Add Stadium to FacilityTemplateCrudController**

In `src/Controller/Admin/FacilityTemplateCrudController.php`, replace:

```php
yield ChoiceField::new('category')
    ->setChoices(['Training' => 'TRAINING', 'Medical' => 'MEDICAL', 'Scouting' => 'SCOUTING']);
```

With:

```php
yield ChoiceField::new('category')
    ->setChoices([
        'Training' => 'TRAINING',
        'Medical'  => 'MEDICAL',
        'Scouting' => 'SCOUTING',
        'Stadium'  => 'STADIUM',
    ]);
```

- [ ] **Step 7: Run test to verify it passes**

```bash
lando php vendor/bin/phpunit tests/Enum/StaffRoleTest.php --testdox
```

Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add src/Enum/StaffRole.php src/Enum/RecruitmentSource.php \
        src/Controller/Admin/StaffCrudController.php \
        src/Controller/Admin/FacilityTemplateCrudController.php \
        tests/Enum/StaffRoleTest.php
git commit -m "feat: add manager/dof/facility-manager roles, stadium facility category, senior_intake source"
```

---

## Task 2: PoolConfig senior player fields

**Files:**
- Modify: `src/Entity/PoolConfig.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Entity/PoolConfigSeniorFieldsTest.php
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Entity/PoolConfigSeniorFieldsTest.php --testdox
```

Expected: FAIL — `getSeniorPlayerAgeMin()` not found

- [ ] **Step 3: Add senior fields to PoolConfig entity**

In `src/Entity/PoolConfig.php`, add after the `// ── Pool Replenishment Targets` section (before the getters/setters block):

```php
    // ── Senior Player Generation ───────────────────────────────────────────

    /** Minimum age for generated senior players. Default: 17 */
    #[ORM\Column(type: 'integer')]
    private int $seniorPlayerAgeMin = 17;

    /** Maximum age for generated senior players. Default: 35 */
    #[ORM\Column(type: 'integer')]
    private int $seniorPlayerAgeMax = 35;

    /** Minimum current ability for generated senior players. Default: 20 */
    #[ORM\Column(type: 'integer')]
    private int $seniorPlayerAbilityMin = 20;

    /** Maximum current ability for generated senior players. Default: 90 */
    #[ORM\Column(type: 'integer')]
    private int $seniorPlayerAbilityMax = 90;

    /** Senior player replenishment target. Default: 200 */
    #[ORM\Column(type: 'integer')]
    private int $seniorPlayerPoolTarget = 200;
```

Then add getters/setters after `getAgentPoolTarget()`/`setAgentPoolTarget()`:

```php
    public function getSeniorPlayerAgeMin(): int { return $this->seniorPlayerAgeMin; }
    public function setSeniorPlayerAgeMin(int $v): static { $this->seniorPlayerAgeMin = $v; return $this; }

    public function getSeniorPlayerAgeMax(): int { return $this->seniorPlayerAgeMax; }
    public function setSeniorPlayerAgeMax(int $v): static { $this->seniorPlayerAgeMax = $v; return $this; }

    public function getSeniorPlayerAbilityMin(): int { return $this->seniorPlayerAbilityMin; }
    public function setSeniorPlayerAbilityMin(int $v): static { $this->seniorPlayerAbilityMin = $v; return $this; }

    public function getSeniorPlayerAbilityMax(): int { return $this->seniorPlayerAbilityMax; }
    public function setSeniorPlayerAbilityMax(int $v): static { $this->seniorPlayerAbilityMax = $v; return $this; }

    public function getSeniorPlayerPoolTarget(): int { return $this->seniorPlayerPoolTarget; }
    public function setSeniorPlayerPoolTarget(int $v): static { $this->seniorPlayerPoolTarget = $v; return $this; }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
lando php vendor/bin/phpunit tests/Entity/PoolConfigSeniorFieldsTest.php --testdox
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Entity/PoolConfig.php tests/Entity/PoolConfigSeniorFieldsTest.php
git commit -m "feat: add senior player fields to PoolConfig entity"
```

---

## Task 3: NpcClub entity + repository

**Files:**
- Create: `src/Entity/NpcClub.php`
- Create: `src/Repository/NpcClubRepository.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Entity/NpcClubTest.php
<?php

namespace App\Tests\Entity;

use App\Entity\NpcClub;
use PHPUnit\Framework\TestCase;

class NpcClubTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $club = new NpcClub(
            name: 'Sevilla FC',
            country: 'ES',
            tier: 2,
            reputation: 75,
            primaryColor: '#c0392b',
            secondaryColor: '#ffffff',
            balance: 5000000,
            facilities: ['training_pitch' => 7, 'north_stand' => 4],
        );

        $this->assertSame('Sevilla FC', $club->getName());
        $this->assertSame('ES', $club->getCountry());
        $this->assertSame(2, $club->getTier());
        $this->assertSame(75, $club->getReputation());
        $this->assertSame('#c0392b', $club->getPrimaryColor());
        $this->assertSame('#ffffff', $club->getSecondaryColor());
        $this->assertSame(5000000, $club->getBalance());
        $this->assertSame(['training_pitch' => 7, 'north_stand' => 4], $club->getFacilities());
        $this->assertNull($club->getStadiumName());
        $this->assertNotNull($club->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $club->getCreatedAt());
    }

    public function testStadiumNameSetter(): void
    {
        $club = new NpcClub('Test FC', 'EN', 1, 80, '#000', '#fff', 1000000, []);
        $club->setStadiumName('The Test Arena');
        $this->assertSame('The Test Arena', $club->getStadiumName());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Entity/NpcClubTest.php --testdox
```

Expected: FAIL — `NpcClub` class not found

- [ ] **Step 3: Create NpcClub entity**

```php
// src/Entity/NpcClub.php
<?php

namespace App\Entity;

use App\Repository\NpcClubRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: NpcClubRepository::class)]
#[ORM\Table(name: 'npc_club')]
class NpcClub
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column(type: 'smallint')]
    private int $reputation;

    #[ORM\Column(length: 7)]
    private string $primaryColor;

    #[ORM\Column(length: 7)]
    private string $secondaryColor;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stadiumName = null;

    #[ORM\Column(type: 'integer')]
    private int $balance;

    #[ORM\Column(type: 'json')]
    private array $facilities;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $name,
        string $country,
        int $tier,
        int $reputation,
        string $primaryColor,
        string $secondaryColor,
        int $balance,
        array $facilities,
    ) {
        $this->id           = new UuidV7();
        $this->name         = $name;
        $this->country      = $country;
        $this->tier         = $tier;
        $this->reputation   = $reputation;
        $this->primaryColor = $primaryColor;
        $this->secondaryColor = $secondaryColor;
        $this->balance      = $balance;
        $this->facilities   = $facilities;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getCountry(): string { return $this->country; }
    public function setCountry(string $v): static { $this->country = $v; return $this; }

    public function getTier(): int { return $this->tier; }
    public function setTier(int $v): static { $this->tier = $v; return $this; }

    public function getReputation(): int { return $this->reputation; }
    public function setReputation(int $v): static { $this->reputation = $v; return $this; }

    public function getPrimaryColor(): string { return $this->primaryColor; }
    public function setPrimaryColor(string $v): static { $this->primaryColor = $v; return $this; }

    public function getSecondaryColor(): string { return $this->secondaryColor; }
    public function setSecondaryColor(string $v): static { $this->secondaryColor = $v; return $this; }

    public function getStadiumName(): ?string { return $this->stadiumName; }
    public function setStadiumName(?string $v): static { $this->stadiumName = $v; return $this; }

    public function getBalance(): int { return $this->balance; }
    public function setBalance(int $v): static { $this->balance = $v; return $this; }

    public function getFacilities(): array { return $this->facilities; }
    public function setFacilities(array $v): static { $this->facilities = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
```

- [ ] **Step 4: Create NpcClubRepository**

```php
// src/Repository/NpcClubRepository.php
<?php

namespace App\Repository;

use App\Entity\NpcClub;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NpcClub>
 */
class NpcClubRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NpcClub::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
lando php vendor/bin/phpunit tests/Entity/NpcClubTest.php --testdox
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Entity/NpcClub.php src/Repository/NpcClubRepository.php tests/Entity/NpcClubTest.php
git commit -m "feat: add NpcClub entity and repository"
```

---

## Task 4: Database migration

**Files:**
- Create: `migrations/Version20260414000001.php`

> Note: Generate the migration automatically after the entity changes from Tasks 2–3 are in place.

- [ ] **Step 1: Generate the migration**

```bash
lando php bin/console doctrine:migrations:diff
```

This creates a new file in `migrations/`. Check the generated SQL covers:
- `ALTER TABLE pool_config ADD senior_player_age_min INT NOT NULL` (and 4 more senior fields)
- `CREATE TABLE npc_club (...)` with all columns

- [ ] **Step 2: Review the generated migration**

Open the generated migration file. Verify it contains:

```sql
-- Expected in up():
ALTER TABLE pool_config ADD senior_player_age_min INT NOT NULL DEFAULT 17;
ALTER TABLE pool_config ADD senior_player_age_max INT NOT NULL DEFAULT 35;
ALTER TABLE pool_config ADD senior_player_ability_min INT NOT NULL DEFAULT 20;
ALTER TABLE pool_config ADD senior_player_ability_max INT NOT NULL DEFAULT 90;
ALTER TABLE pool_config ADD senior_player_pool_target INT NOT NULL DEFAULT 200;

CREATE TABLE npc_club (
    id UUID NOT NULL,
    name VARCHAR(100) NOT NULL,
    country VARCHAR(2) NOT NULL,
    tier SMALLINT NOT NULL,
    reputation SMALLINT NOT NULL,
    primary_color VARCHAR(7) NOT NULL,
    secondary_color VARCHAR(7) NOT NULL,
    stadium_name VARCHAR(100) DEFAULT NULL,
    balance INT NOT NULL,
    facilities JSON NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY(id)
);
COMMENT ON COLUMN npc_club.id IS '(DC2Type:uuid)';
COMMENT ON COLUMN npc_club.created_at IS '(DC2Type:datetime_immutable)';
```

If the migration looks correct, proceed. If Doctrine generated something unexpected (wrong types, missing columns), edit it manually to match the above.

- [ ] **Step 3: Run the migration**

```bash
lando php bin/console doctrine:migrations:migrate --no-interaction
```

Expected: Migration executed successfully.

- [ ] **Step 4: Verify schema**

```bash
lando psql -c "\d npc_club"
lando psql -c "\d pool_config" | grep senior
```

Expected: `npc_club` table exists with all columns; `pool_config` has 5 new `senior_*` columns.

- [ ] **Step 5: Commit**

```bash
git add migrations/
git commit -m "feat: migration — add npc_club table and senior player fields to pool_config"
```

---

## Task 5: PlayerRepository senior pool helpers

**Files:**
- Modify: `src/Repository/PlayerRepository.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Repository/PlayerRepositoryCountSeniorTest.php
<?php

namespace App\Tests\Repository;

use App\Enum\RecruitmentSource;
use App\Repository\PlayerRepository;
use PHPUnit\Framework\TestCase;

class PlayerRepositoryCountSeniorTest extends TestCase
{
    public function testCountSeniorInPoolMethodExists(): void
    {
        // Structural test — verifies method signature is callable via reflection
        $ref = new \ReflectionClass(PlayerRepository::class);
        $this->assertTrue($ref->hasMethod('countSeniorInPool'));
        $method = $ref->getMethod('countSeniorInPool');
        $this->assertSame(0, $method->getNumberOfRequiredParameters());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Repository/PlayerRepositoryCountSeniorTest.php --testdox
```

Expected: FAIL — method `countSeniorInPool` not found

- [ ] **Step 3: Add countSeniorInPool to PlayerRepository**

In `src/Repository/PlayerRepository.php`, add after `countInPool()`:

```php
    public function countSeniorInPool(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.academy IS NULL')
            ->andWhere('p.recruitmentSource = :source')
            ->setParameter('source', RecruitmentSource::SENIOR_INTAKE)
            ->getQuery()
            ->getSingleScalarResult();
    }
```

Also add the import at the top of the file if `RecruitmentSource` is not already imported:

```php
use App\Enum\RecruitmentSource;
```

- [ ] **Step 4: Run test to verify it passes**

```bash
lando php vendor/bin/phpunit tests/Repository/PlayerRepositoryCountSeniorTest.php --testdox
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Repository/PlayerRepository.php tests/Repository/PlayerRepositoryCountSeniorTest.php
git commit -m "feat: add countSeniorInPool to PlayerRepository"
```

---

## Task 6: MarketPoolService — generateSeniorPlayers + replenishment

**Files:**
- Modify: `src/Service/MarketPoolService.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Service/MarketPoolServiceSeniorTest.php
<?php

namespace App\Tests\Service;

use App\Service\MarketPoolService;
use PHPUnit\Framework\TestCase;

class MarketPoolServiceSeniorTest extends TestCase
{
    public function testGenerateSeniorPlayersMethodExists(): void
    {
        $ref = new \ReflectionClass(MarketPoolService::class);
        $this->assertTrue($ref->hasMethod('generateSeniorPlayers'));
        $method = $ref->getMethod('generateSeniorPlayers');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('count', $params[0]->getName());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Service/MarketPoolServiceSeniorTest.php --testdox
```

Expected: FAIL — `generateSeniorPlayers` method not found

- [ ] **Step 3: Add generateSeniorPlayers to MarketPoolService**

In `src/Service/MarketPoolService.php`, add after the `generatePlayers()` method:

```php
    /** @return Player[] Senior players (age 17–35, no guardians) for the shared pool */
    public function generateSeniorPlayers(int $count): array
    {
        $cfg    = $this->poolConfigRepo->getConfig();
        $agents = $this->agentRepo->findAll();
        $players = [];

        for ($i = 0; $i < $count; $i++) {
            $currentAbility = random_int($cfg->getSeniorPlayerAbilityMin(), $cfg->getSeniorPlayerAbilityMax());
            $age            = random_int($cfg->getSeniorPlayerAgeMin(), $cfg->getSeniorPlayerAgeMax());
            $nat            = $this->nameGenerator->getRandomNationality();

            ['firstName' => $firstName, 'lastName' => $lastName] = $this->nameGenerator->generatePlayerName($nat);

            $player = new Player(
                firstName:         $firstName,
                lastName:          $lastName,
                dateOfBirth:       $this->dobFromAge($age),
                nationality:       $nat,
                position:          $this->weightedPosition($cfg),
                recruitmentSource: RecruitmentSource::SENIOR_INTAKE,
                potential:         $currentAbility, // Senior players are at or near their peak
                currentAbility:    $currentAbility,
                academy:           null,
            );

            $player->setStatus(PlayerStatus::ACTIVE);
            $baseWage = $currentAbility * random_int(50, 200);
            $player->setContractValue($baseWage);

            $attrBudget = (int) round($currentAbility * 1.2);
            $attrs      = $this->distributeAttributes($player->getPosition(), $attrBudget);
            $player->setPace($attrs['pace']);
            $player->setTechnical($attrs['technical']);
            $player->setVision($attrs['vision']);
            $player->setPower($attrs['power']);
            $player->setStamina($attrs['stamina']);
            $player->setHeart($attrs['heart']);

            $player->setHeight(random_int(165, 195));
            $player->setWeight(random_int(65, 90));

            if (!empty($agents) && random_int(1, 100) <= $cfg->getPlayerAgentChancePercent()) {
                $player->setAgent($agents[array_rand($agents)]);
            }

            $pMin = $cfg->getPersonalityTraitMin();
            $pMax = $cfg->getPersonalityTraitMax();
            $p    = $player->getPersonality();
            $p->setConfidence(random_int($pMin, $pMax));
            $p->setMaturity(random_int($pMin, $pMax));
            $p->setTeamwork(random_int($pMin, $pMax));
            $p->setLeadership(random_int($pMin, $pMax));
            $p->setEgo(random_int($pMin, $pMax));
            $p->setBravery(random_int($pMin, $pMax));
            $p->setGreed(random_int($pMin, $pMax));
            $p->setLoyalty(random_int($pMin, $pMax));

            // No guardians for senior players.

            $this->em->persist($player);
            $players[] = $player;

            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Player::class);
                $agents = $this->agentRepo->findAll();
            }
        }

        $this->em->flush();
        return $players;
    }
```

Make sure `RecruitmentSource` and `PlayerStatus` are imported at the top of the file (they already are).

- [ ] **Step 4: Extend replenishPool() to check senior pool**

In `replenishPool()`, add after the existing `agentRepo` block (before `return $generated`):

```php
        if ($this->playerRepo->countSeniorInPool() < $cfg->getSeniorPlayerPoolTarget()) {
            $need = $cfg->getSeniorPlayerPoolTarget() - $this->playerRepo->countSeniorInPool();
            $this->generateSeniorPlayers($need);
            $generated[] = $need . ' senior players';
        }
```

- [ ] **Step 5: Extend forceGeneratePool() to add senior players**

In `forceGeneratePool()`, add after the `generateAgents()` block (before `return $generated`):

```php
        $this->generateSeniorPlayers($cfg->getSeniorPlayerPoolTarget());
        $generated[] = $cfg->getSeniorPlayerPoolTarget() . ' senior players';
```

- [ ] **Step 6: Run test to verify it passes**

```bash
lando php vendor/bin/phpunit tests/Service/MarketPoolServiceSeniorTest.php --testdox
```

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Service/MarketPoolService.php tests/Service/MarketPoolServiceSeniorTest.php
git commit -m "feat: add generateSeniorPlayers and extend replenishment in MarketPoolService"
```

---

## Task 7: NpcClubGenerationService

**Files:**
- Create: `src/Service/NpcClubGenerationService.php`
- Create: `tests/Service/NpcClubGenerationServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Service/NpcClubGenerationServiceTest.php
<?php

namespace App\Tests\Service;

use App\Entity\FacilityTemplate;
use App\Entity\NpcClub;
use App\Repository\FacilityTemplateRepository;
use App\Repository\NpcClubRepository;
use App\Service\NpcClubGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NpcClubGenerationServiceTest extends TestCase
{
    private function makeService(array $slugs = ['training_pitch', 'north_stand', 'physio_clinic']): NpcClubGenerationService
    {
        $em      = $this->createStub(EntityManagerInterface::class);
        $repo    = $this->createStub(FacilityTemplateRepository::class);
        $clubRepo = $this->createStub(NpcClubRepository::class);

        $templates = array_map(function (string $slug) {
            $t = $this->createStub(FacilityTemplate::class);
            $t->method('getSlug')->willReturn($slug);
            $t->method('getCategory')->willReturn('TRAINING');
            return $t;
        }, $slugs);

        $repo->method('findBy')->willReturn($templates);

        return new NpcClubGenerationService($em, $repo, $clubRepo);
    }

    public function testGeneratesCorrectCount(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(5, 3, 'ES');
        $this->assertCount(5, $clubs);
        foreach ($clubs as $club) {
            $this->assertInstanceOf(NpcClub::class, $club);
        }
    }

    public function testClubHasCorrectCountryAndTier(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(1, 4, 'DE');
        $this->assertSame('DE', $clubs[0]->getCountry());
        $this->assertSame(4, $clubs[0]->getTier());
    }

    public function testTier1ReputationIsHigh(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(10, 1, 'ES');
        foreach ($clubs as $club) {
            $this->assertGreaterThanOrEqual(70, $club->getReputation());
            $this->assertLessThanOrEqual(90, $club->getReputation());
        }
    }

    public function testTier8ReputationIsLow(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(10, 8, 'ES');
        foreach ($clubs as $club) {
            $this->assertGreaterThanOrEqual(5, $club->getReputation());
            $this->assertLessThanOrEqual(20, $club->getReputation());
        }
    }

    public function testFacilitiesAreAssigned(): void
    {
        $service = $this->makeService(['training_pitch', 'north_stand', 'physio_clinic']);
        $clubs   = $service->generateClubs(1, 1, 'ES');
        $facs    = $clubs[0]->getFacilities();
        $this->assertArrayHasKey('training_pitch', $facs);
        $this->assertArrayHasKey('north_stand', $facs);
        $this->assertArrayHasKey('physio_clinic', $facs);
    }

    public function testNameIsNonEmpty(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(3, 2, 'ES');
        foreach ($clubs as $club) {
            $this->assertNotEmpty($club->getName());
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --testdox
```

Expected: FAIL — `NpcClubGenerationService` class not found

- [ ] **Step 3: Create NpcClubGenerationService**

```php
// src/Service/NpcClubGenerationService.php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FacilityTemplate;
use App\Entity\NpcClub;
use App\Repository\FacilityTemplateRepository;
use App\Repository\NpcClubRepository;
use Doctrine\ORM\EntityManagerInterface;

class NpcClubGenerationService
{
    // ── Place names by ISO country code ──────────────────────────────────────
    private const PLACE_NAMES_BY_COUNTRY = [
        'ES' => ['Sevilla', 'Córdoba', 'Granada', 'Murcia', 'Valencia', 'Bilbao', 'Zaragoza', 'Málaga', 'Alicante', 'Valladolid'],
        'EN' => ['Norwich', 'Bristol', 'Derby', 'Bolton', 'Preston', 'Crewe', 'Carlisle', 'Exeter', 'Shrewsbury', 'Grimsby'],
        'DE' => ['Dortmund', 'Düsseldorf', 'Hannover', 'Nürnberg', 'Augsburg', 'Bielefeld', 'Mainz', 'Kaiserslautern', 'Karlsruhe', 'Freiburg'],
        'IT' => ['Palermo', 'Catania', 'Bari', 'Brescia', 'Verona', 'Perugia', 'Livorno', 'Modena', 'Reggio', 'Pescara'],
        'FR' => ['Nantes', 'Bordeaux', 'Strasbourg', 'Lille', 'Rennes', 'Reims', 'Metz', 'Caen', 'Dijon', 'Grenoble'],
        'BR' => ['Santos', 'Recife', 'Fortaleza', 'Manaus', 'Curitiba', 'Goiânia', 'Campinas', 'Belém', 'Vitória', 'Maceió'],
        'AR' => ['Córdoba', 'Rosario', 'Mendoza', 'Tucumán', 'Mar del Plata', 'Salta', 'Lanús', 'Quilmes', 'Banfield', 'Platense'],
        'NL' => ['Utrecht', 'Groningen', 'Eindhoven', 'Tilburg', 'Breda', 'Almere', 'Nijmegen', 'Arnhem', 'Zwolle', 'Heerenveen'],
        'PT' => ['Braga', 'Guimarães', 'Setúbal', 'Coimbra', 'Faro', 'Évora', 'Aveiro', 'Leiria', 'Funchal', 'Viseu'],
    ];

    private const SUFFIXES = ['FC', 'CF', 'Athletic', 'United', 'City', 'Rovers', 'Town', 'SC', 'Deportivo', 'Wanderers'];

    private const COLORS = [
        '#c0392b', '#2980b9', '#27ae60', '#8e44ad', '#f39c12',
        '#16a085', '#d35400', '#2c3e50', '#e74c3c', '#1abc9c',
        '#3498db', '#9b59b6', '#e67e22', '#1a252f', '#ffffff',
        '#2ecc71', '#e8d44d', '#34495e', '#922b21', '#1f618d',
    ];

    /**
     * Facility level ranges by tier band.
     * Keys are tier ranges, values are [training, standLevel, medicalScouting]
     */
    private const FACILITY_LEVELS_BY_TIER = [
        [1, 2] => ['training' => [7, 9], 'stands' => [4, 5], 'other' => [3, 5]],
        [3, 4] => ['training' => [5, 6], 'stands' => [3, 4], 'other' => [2, 3]],
        [5, 6] => ['training' => [3, 4], 'stands' => [2, 3], 'other' => [1, 2]],
        [7, 8] => ['training' => [1, 2], 'stands' => [0, 1], 'other' => [0, 1]],
    ];

    // Training-related slugs (determines which level band to apply)
    private const TRAINING_SLUGS = ['training_pitch', 'strength_suite'];
    // Stadium stand slugs
    private const STANDS_SLUGS   = ['north_stand', 'south_stand', 'east_stand', 'west_stand'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FacilityTemplateRepository $facilityTemplateRepo,
        private readonly NpcClubRepository $npcClubRepo,
    ) {}

    /** @return NpcClub[] */
    public function generateClubs(int $count, int $tier, string $country): array
    {
        $tier      = max(1, min(8, $tier));
        $slugs     = $this->getActiveFacilitySlugs();
        $levelBand = $this->getLevelBandForTier($tier);
        $placeNames = self::PLACE_NAMES_BY_COUNTRY[$country] ?? ['Capital', 'Northern', 'Southern', 'Eastern', 'Western', 'Central'];
        $usedNames = [];
        $clubs     = [];

        for ($i = 0; $i < $count; $i++) {
            $name       = $this->generateName($placeNames, $usedNames);
            $usedNames[] = $name;
            $reputation = $this->reputationForTier($tier);
            $balance    = $this->balanceForTier($tier);
            $facilities = $this->buildFacilities($slugs, $levelBand);
            $colors     = $this->pickColorPair();

            $club = new NpcClub(
                name:           $name,
                country:        $country,
                tier:           $tier,
                reputation:     $reputation,
                primaryColor:   $colors[0],
                secondaryColor: $colors[1],
                balance:        $balance,
                facilities:     $facilities,
            );

            $this->em->persist($club);
            $clubs[] = $club;
        }

        $this->em->flush();
        return $clubs;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return string[] active facility slugs from DB */
    private function getActiveFacilitySlugs(): array
    {
        $templates = $this->facilityTemplateRepo->findBy(['isActive' => true]);
        return array_map(fn(FacilityTemplate $t) => $t->getSlug(), $templates);
    }

    private function getLevelBandForTier(int $tier): array
    {
        foreach (self::FACILITY_LEVELS_BY_TIER as $range => $levels) {
            [$min, $max] = $range;
            if ($tier >= $min && $tier <= $max) {
                return $levels;
            }
        }
        return self::FACILITY_LEVELS_BY_TIER[[7, 8]];
    }

    private function buildFacilities(array $slugs, array $band): array
    {
        $facilities = [];
        foreach ($slugs as $slug) {
            if (in_array($slug, self::TRAINING_SLUGS, true)) {
                $facilities[$slug] = random_int($band['training'][0], max($band['training'][0], $band['training'][1]));
            } elseif (in_array($slug, self::STANDS_SLUGS, true)) {
                $facilities[$slug] = random_int($band['stands'][0], max($band['stands'][0], $band['stands'][1]));
            } else {
                $facilities[$slug] = random_int($band['other'][0], max($band['other'][0], $band['other'][1]));
            }
        }
        return $facilities;
    }

    private function generateName(array $placeNames, array $usedNames): string
    {
        $attempts = 0;
        do {
            $place  = $placeNames[array_rand($placeNames)];
            $suffix = self::SUFFIXES[array_rand(self::SUFFIXES)];
            $name   = "{$place} {$suffix}";
            $attempts++;
        } while (in_array($name, $usedNames, true) && $attempts < 50);

        return $name;
    }

    private function reputationForTier(int $tier): int
    {
        // tier 1 → 70–90, tier 8 → 5–20 (linear interpolation)
        $minRep = (int) round(70 - ($tier - 1) * (65 / 7));
        $maxRep = (int) round(90 - ($tier - 1) * (70 / 7));
        return random_int(max(1, $minRep), max(1, $maxRep));
    }

    private function balanceForTier(int $tier): int
    {
        // tier 1 → ~£50m, tier 8 → ~£500k (in pence)
        $base = (int) (5_000_000_000 / pow(2, $tier - 1));
        $variance = (int) ($base * 0.2);
        return random_int(max(0, $base - $variance), $base + $variance);
    }

    /** @return string[] [primaryColor, secondaryColor] */
    private function pickColorPair(): array
    {
        $primary   = self::COLORS[array_rand(self::COLORS)];
        $secondary = self::COLORS[array_rand(self::COLORS)];
        return [$primary, $secondary];
    }
}
```

> Note: The `FACILITY_LEVELS_BY_TIER` constant uses array keys that are arrays. PHP does not support array keys like `[1, 2]` in `const`. Replace with an indexed structure instead.

Correct the constant definition and `getLevelBandForTier()`:

```php
    private const FACILITY_LEVELS = [
        ['min' => 1, 'max' => 2, 'training' => [7, 9], 'stands' => [4, 5], 'other' => [3, 5]],
        ['min' => 3, 'max' => 4, 'training' => [5, 6], 'stands' => [3, 4], 'other' => [2, 3]],
        ['min' => 5, 'max' => 6, 'training' => [3, 4], 'stands' => [2, 3], 'other' => [1, 2]],
        ['min' => 7, 'max' => 8, 'training' => [1, 2], 'stands' => [0, 1], 'other' => [0, 1]],
    ];
```

And update `getLevelBandForTier()`:

```php
    private function getLevelBandForTier(int $tier): array
    {
        foreach (self::FACILITY_LEVELS as $band) {
            if ($tier >= $band['min'] && $tier <= $band['max']) {
                return $band;
            }
        }
        return self::FACILITY_LEVELS[3]; // fallback to tier 7–8
    }
```

Use `$band['training']`, `$band['stands']`, `$band['other']` in `buildFacilities()`.

- [ ] **Step 4: Run tests to verify they pass**

```bash
lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --testdox
```

Expected: PASS (all 6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Service/NpcClubGenerationService.php tests/Service/NpcClubGenerationServiceTest.php
git commit -m "feat: add NpcClubGenerationService with hybrid naming and tier-based facility levels"
```

---

## Task 8: POST /api/market/consume endpoint

**Files:**
- Create: `src/Dto/ConsumeRequest.php`
- Modify: `src/Controller/Api/MarketController.php`
- Create: `tests/Controller/Api/MarketConsumeTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Controller/Api/MarketConsumeTest.php
<?php

namespace App\Tests\Controller\Api;

use App\Dto\ConsumeRequest;
use PHPUnit\Framework\TestCase;

class MarketConsumeTest extends TestCase
{
    public function testConsumeRequestDto(): void
    {
        $dto = new ConsumeRequest(
            playerIds: ['uuid-1', 'uuid-2'],
            staffIds:  ['uuid-3'],
            scoutIds:  [],
        );

        $this->assertSame(['uuid-1', 'uuid-2'], $dto->playerIds);
        $this->assertSame(['uuid-3'], $dto->staffIds);
        $this->assertSame([], $dto->scoutIds);
    }

    public function testConsumeMethodExistsOnController(): void
    {
        $ref = new \ReflectionClass(\App\Controller\Api\MarketController::class);
        $this->assertTrue($ref->hasMethod('consume'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Controller/Api/MarketConsumeTest.php --testdox
```

Expected: FAIL — `ConsumeRequest` not found

- [ ] **Step 3: Create ConsumeRequest DTO**

```php
// src/Dto/ConsumeRequest.php
<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ConsumeRequest
{
    public function __construct(
        /** @var string[] */
        #[Assert\All([new Assert\Uuid()])]
        public readonly array $playerIds = [],

        /** @var string[] */
        #[Assert\All([new Assert\Uuid()])]
        public readonly array $staffIds = [],

        /** @var string[] */
        #[Assert\All([new Assert\Uuid()])]
        public readonly array $scoutIds = [],
    ) {}
}
```

- [ ] **Step 4: Add consume endpoint to MarketController**

In `src/Controller/Api/MarketController.php`, add the following method and required imports.

Add imports after existing `use` statements:

```php
use App\Dto\ConsumeRequest;
use App\Repository\NpcClubRepository;
```

Add repository constructor injection or use method-level injection (following existing pattern with method parameters):

```php
    #[Route('/consume', name: 'api_market_consume', methods: ['POST'])]
    #[IsGranted('ROLE_ACADEMY')]
    public function consume(
        #[MapRequestPayload] ConsumeRequest $dto,
        PlayerRepository $playerRepo,
        StaffRepository  $staffRepo,
        ScoutRepository  $scoutRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $deleted = ['players' => 0, 'staff' => 0, 'scouts' => 0];

        foreach ($dto->playerIds as $id) {
            try {
                $entity = $playerRepo->find(Uuid::fromString($id));
                if ($entity !== null) {
                    $em->remove($entity);
                    $deleted['players']++;
                }
            } catch (\Throwable) {
                // Silently ignore invalid/unknown IDs
            }
        }

        foreach ($dto->staffIds as $id) {
            try {
                $entity = $staffRepo->find(Uuid::fromString($id));
                if ($entity !== null) {
                    $em->remove($entity);
                    $deleted['staff']++;
                }
            } catch (\Throwable) {
                // Silently ignore invalid/unknown IDs
            }
        }

        foreach ($dto->scoutIds as $id) {
            try {
                $entity = $scoutRepo->find(Uuid::fromString($id));
                if ($entity !== null) {
                    $em->remove($entity);
                    $deleted['scouts']++;
                }
            } catch (\Throwable) {
                // Silently ignore invalid/unknown IDs
            }
        }

        $em->flush();

        return $this->json(['deleted' => $deleted]);
    }
```

Also add the `EntityManagerInterface` import:

```php
use Doctrine\ORM\EntityManagerInterface;
```

- [ ] **Step 5: Run test to verify it passes**

```bash
lando php vendor/bin/phpunit tests/Controller/Api/MarketConsumeTest.php --testdox
```

Expected: PASS

- [ ] **Step 6: Verify route is registered**

```bash
lando php bin/console debug:router | grep consume
```

Expected: `api_market_consume   POST   /api/market/consume`

- [ ] **Step 7: Commit**

```bash
git add src/Dto/ConsumeRequest.php src/Controller/Api/MarketController.php \
        tests/Controller/Api/MarketConsumeTest.php
git commit -m "feat: add POST /api/market/consume endpoint for hard-deleting claimed pool entities"
```

---

## Task 9: NpcClubCrudController

**Files:**
- Create: `src/Controller/Admin/NpcClubCrudController.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Controller/Admin/NpcClubCrudControllerTest.php
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Controller/Admin/NpcClubCrudControllerTest.php --testdox
```

Expected: FAIL — `NpcClubCrudController` not found

- [ ] **Step 3: Create NpcClubCrudController**

```php
// src/Controller/Admin/NpcClubCrudController.php
<?php

namespace App\Controller\Admin;

use App\Entity\NpcClub;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class NpcClubCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NpcClub::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield TextField::new('country')
            ->setHelp('ISO 2-letter code, e.g. ES, EN, DE');
        yield IntegerField::new('tier')
            ->setHelp('1 (top) to 8 (bottom)');
        yield IntegerField::new('reputation')
            ->setHelp('0–100');
        yield ColorField::new('primaryColor');
        yield ColorField::new('secondaryColor');
        yield TextField::new('stadiumName')
            ->setHelp('Optional stadium name, e.g. Estadio El Cid')
            ->hideOnIndex();
        yield IntegerField::new('balance')
            ->setHelp('Starting budget in pence');
        yield TextareaField::new('facilities')
            ->setHelp('JSON: {"training_pitch": 6, "north_stand": 4, ...}')
            ->hideOnIndex()
            ->formatValue(function ($value) {
                return is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value;
            });
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
lando php vendor/bin/phpunit tests/Controller/Admin/NpcClubCrudControllerTest.php --testdox
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/NpcClubCrudController.php \
        tests/Controller/Admin/NpcClubCrudControllerTest.php
git commit -m "feat: add NpcClubCrudController for EasyAdmin NPC club management"
```

---

## Task 10: DashboardController — NPC club routes + menu

**Files:**
- Modify: `src/Controller/Admin/DashboardController.php`

- [ ] **Step 1: Add constructor injections**

In `DashboardController`, the constructor currently injects `MarketPoolService` and `PlayerRepository`. Add:

```php
use App\Repository\NpcClubRepository;
use App\Service\NpcClubGenerationService;
```

Add to constructor:

```php
    public function __construct(
        private EntityManagerInterface $em,
        private GameConfigRepository $gameConfigRepository,
        private StarterConfigRepository $starterConfigRepository,
        private PoolConfigRepository $poolConfigRepository,
        private MarketPoolService $marketPoolService,
        private PlayerRepository $playerRepository,
        private NpcClubGenerationService $npcClubGenerationService,
        private NpcClubRepository $npcClubRepository,
    ) {}
```

- [ ] **Step 2: Add NPC clubs routes**

Add these three routes after the Config Import/Export section (before the Developer Tools section):

```php
    // ── NPC Clubs ─────────────────────────────────────────────────────────

    #[Route('/admin/npc-clubs/content', name: 'admin_npc_clubs_content')]
    #[IsGranted('ROLE_ADMIN')]
    public function npcClubsContent(): Response
    {
        $config = $this->poolConfigRepository->getConfig();
        return $this->render('admin/npc_clubs_content.html.twig', [
            'config'    => $config,
            'clubCount' => $this->npcClubRepository->count([]),
        ]);
    }

    #[Route('/admin/npc-clubs/generate', name: 'admin_npc_clubs_generate', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateNpcClubs(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('generate_npc_clubs', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $country = strtoupper(trim($request->request->get('country', '')));
        $tier    = (int) $request->request->get('tier', 4);
        $count   = (int) $request->request->get('count', 8);

        if ($country === '' || $tier < 1 || $tier > 8 || $count < 1) {
            $this->addFlash('danger', 'Invalid parameters — country, tier (1–8) and count are required.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $clubs = $this->npcClubGenerationService->generateClubs($count, $tier, $country);
        $this->addFlash('success', sprintf('Generated %d clubs for %s — Tier %d.', count($clubs), $country, $tier));

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
    }

    #[Route('/admin/npc-clubs/replenish-senior', name: 'admin_npc_clubs_replenish_senior', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function replenishSeniorPool(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('replenish_senior_pool', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $cfg     = $this->poolConfigRepository->getConfig();
        $current = $this->playerRepository->countSeniorInPool();
        $target  = $cfg->getSeniorPlayerPoolTarget();

        if ($current >= $target) {
            $this->addFlash('info', "Senior pool already at target — {$current} / {$target}.");
        } else {
            $needed = $target - $current;
            $this->marketPoolService->generateSeniorPlayers($needed);
            $newCount = $this->playerRepository->countSeniorInPool();
            $this->addFlash('success', "Generated {$needed} senior players — pool now at {$newCount}.");
        }

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
    }
```

- [ ] **Step 3: Add Pool Config senior fields save support**

In the existing `poolConfigSave()` method, add handling for the new senior fields. Find the section where pool config fields are saved (look for `setPlayerPoolTarget` or similar) and add:

```php
        if ($request->request->has('seniorPlayerAgeMin')) {
            $config->setSeniorPlayerAgeMin((int) $request->request->get('seniorPlayerAgeMin'));
        }
        if ($request->request->has('seniorPlayerAgeMax')) {
            $config->setSeniorPlayerAgeMax((int) $request->request->get('seniorPlayerAgeMax'));
        }
        if ($request->request->has('seniorPlayerAbilityMin')) {
            $config->setSeniorPlayerAbilityMin((int) $request->request->get('seniorPlayerAbilityMin'));
        }
        if ($request->request->has('seniorPlayerAbilityMax')) {
            $config->setSeniorPlayerAbilityMax((int) $request->request->get('seniorPlayerAbilityMax'));
        }
        if ($request->request->has('seniorPlayerPoolTarget')) {
            $config->setSeniorPlayerPoolTarget((int) $request->request->get('seniorPlayerPoolTarget'));
        }
```

- [ ] **Step 4: Add NPC Clubs menu section to configureMenuItems()**

In `configureMenuItems()`, add after the `Market` section:

```php
        yield MenuItem::section('Clubs & Leagues');
        yield MenuItem::linkTo(NpcClubCrudController::class, 'NPC Clubs', 'fa fa-shield-halved');
        yield MenuItem::linkToRoute('Generate', 'fa fa-wand-magic-sparkles', 'admin_npc_clubs_content');
```

Also add the import at the top of `DashboardController.php`:

```php
use App\Controller\Admin\NpcClubCrudController;
```

- [ ] **Step 5: Verify routes**

```bash
lando php bin/console debug:router | grep npc
```

Expected:
```
admin_npc_clubs_content          GET    /admin/npc-clubs/content
admin_npc_clubs_generate         POST   /admin/npc-clubs/generate
admin_npc_clubs_replenish_senior POST   /admin/npc-clubs/replenish-senior
```

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Admin/DashboardController.php
git commit -m "feat: add NPC clubs admin routes and menu section to DashboardController"
```

---

## Task 11: pool_config.html.twig — senior player fields

**Files:**
- Modify: `templates/admin/pool_config.html.twig`

- [ ] **Step 1: Add senior player card to pool_config.html.twig**

In `templates/admin/pool_config.html.twig`, add a new card after the existing Pool Targets card (after the closing `</div>` of that card), before the `{# ── Player Age & Core Stats ── #}` card:

```twig
            {# ── Senior Player Pool ──────────────────────────────────── #}
            <div class="card">
                <div class="card-header py-2 d-flex align-items-center gap-2">
                    <i class="fa fa-user text-muted"></i>
                    <span class="fw-semibold small">Senior Players</span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Configuration for senior player generation (age 17–35, no guardians).
                        Senior players are added to the same shared pool as youth players.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="seniorPlayerPoolTarget" class="form-label fw-semibold small">Pool Target</label>
                            <input type="number" id="seniorPlayerPoolTarget" name="seniorPlayerPoolTarget"
                                   value="{{ config.seniorPlayerPoolTarget }}" min="1" class="form-control">
                            <div class="form-text">Default: 200</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Age Range</label>
                            <div class="input-group">
                                <input type="number" name="seniorPlayerAgeMin" value="{{ config.seniorPlayerAgeMin }}"
                                       min="16" max="50" class="form-control" placeholder="Min">
                                <span class="input-group-text">–</span>
                                <input type="number" name="seniorPlayerAgeMax" value="{{ config.seniorPlayerAgeMax }}"
                                       min="16" max="50" class="form-control" placeholder="Max">
                            </div>
                            <div class="form-text">Default: 17–35</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Ability Range</label>
                            <div class="input-group">
                                <input type="number" name="seniorPlayerAbilityMin" value="{{ config.seniorPlayerAbilityMin }}"
                                       min="1" max="100" class="form-control" placeholder="Min">
                                <span class="input-group-text">–</span>
                                <input type="number" name="seniorPlayerAbilityMax" value="{{ config.seniorPlayerAbilityMax }}"
                                       min="1" max="100" class="form-control" placeholder="Max">
                            </div>
                            <div class="form-text">Current ability. Default: 20–90</div>
                        </div>
                    </div>
                </div>
            </div>
```

Also update the Pool Status section to include senior players. In the `poolItems` Twig array, add:

```twig
                        { label: 'Sr. Players', icon: 'user', count: poolCounts.seniorPlayers, target: config.seniorPlayerPoolTarget },
```

This requires `poolCounts.seniorPlayers` to be passed from the controller. Find the `poolConfig()` route in `DashboardController.php` and add `seniorPlayers` to the `poolCounts` array:

```php
'poolCounts' => [
    // ... existing entries ...
    'seniorPlayers' => $this->playerRepository->countSeniorInPool(),
],
```

- [ ] **Step 2: Verify the page renders without errors**

```bash
lando php bin/console cache:clear
```

Then visit `/admin?routeName=admin_pool_config` in a browser (or via curl if you have a token). Check no Twig exceptions occur.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/pool_config.html.twig src/Controller/Admin/DashboardController.php
git commit -m "feat: add senior player fields to Pool Config admin page"
```

---

## Task 12: npc_clubs_content.html.twig

**Files:**
- Create: `templates/admin/npc_clubs_content.html.twig`

- [ ] **Step 1: Create the template**

```twig
{# templates/admin/npc_clubs_content.html.twig #}
{% extends '@EasyAdmin/layout.html.twig' %}

{% block content %}
<div class="row g-4 mt-1">

    <div class="col-12">
        <h5 class="fw-semibold mb-0">Clubs &amp; Leagues — Quick Actions</h5>
        <p class="text-muted small mb-0">
            Generate NPC clubs for a specific country and tier, or replenish the senior player pool.
            NPC clubs are pure metadata — squads are assembled by the frontend at game-start using the player pool.
        </p>
    </div>

    {# ── Status ─────────────────────────────────────────────────────────── #}
    <div class="col-12 col-md-3">
        <div class="card h-100">
            <div class="card-header py-2 d-flex align-items-center gap-2">
                <i class="fa fa-shield-halved text-muted"></i>
                <span class="fw-semibold small">Club Pool</span>
            </div>
            <div class="card-body text-center">
                <div class="display-6 fw-bold">{{ clubCount }}</div>
                <div class="text-muted small">NPC clubs stored</div>
            </div>
        </div>
    </div>

    {# ── Generate Clubs ─────────────────────────────────────────────────── #}
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header py-2 d-flex align-items-center gap-2">
                <i class="fa fa-wand-magic-sparkles text-primary"></i>
                <span class="fw-semibold small">Generate Clubs</span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Generates NPC clubs for a specific country and league tier.
                    Facility levels, reputation, and balance are preset by tier band.
                </p>
                <form method="POST" action="{{ path('admin_npc_clubs_generate') }}">
                    <input type="hidden" name="_token" value="{{ csrf_token('generate_npc_clubs') }}">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="country" class="form-label fw-semibold small">Country (ISO)</label>
                            <select id="country" name="country" class="form-select form-select-sm">
                                <option value="ES">Spain (ES)</option>
                                <option value="EN">England (EN)</option>
                                <option value="DE">Germany (DE)</option>
                                <option value="IT">Italy (IT)</option>
                                <option value="FR">France (FR)</option>
                                <option value="BR">Brazil (BR)</option>
                                <option value="AR">Argentina (AR)</option>
                                <option value="NL">Netherlands (NL)</option>
                                <option value="PT">Portugal (PT)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="tier" class="form-label fw-semibold small">Tier (1–8)</label>
                            <input type="number" id="tier" name="tier" value="4"
                                   min="1" max="8" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label for="count" class="form-label fw-semibold small">Count</label>
                            <input type="number" id="count" name="count" value="8"
                                   min="1" max="50" class="form-control form-control-sm">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa fa-plus me-1"></i>Generate Clubs
                    </button>
                </form>
            </div>
        </div>
    </div>

    {# ── Replenish Senior Pool ───────────────────────────────────────────── #}
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header py-2 d-flex align-items-center gap-2">
                <i class="fa fa-person-running text-success"></i>
                <span class="fw-semibold small">Replenish Senior Pool</span>
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <p class="text-muted small mb-0">
                    Generates senior players (age 17–35) up to the configured
                    <code>seniorPlayerPoolTarget</code> (currently {{ config.seniorPlayerPoolTarget }}).
                    No guardians are created for senior players.
                </p>
                <div class="mt-auto">
                    <form method="POST" action="{{ path('admin_npc_clubs_replenish_senior') }}">
                        <input type="hidden" name="_token" value="{{ csrf_token('replenish_senior_pool') }}">
                        <button type="submit" class="btn btn-success btn-sm"
                                onclick="return confirm('Replenish senior player pool up to target?')">
                            <i class="fa fa-arrow-up me-1"></i>Replenish Senior Pool
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
{% endblock %}
```

- [ ] **Step 2: Clear cache and verify no render errors**

```bash
lando php bin/console cache:clear
```

Visit `/admin?routeName=admin_npc_clubs_content` to confirm the page renders.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/npc_clubs_content.html.twig
git commit -m "feat: add npc_clubs_content admin template for generate clubs and senior pool actions"
```

---

## Task 13: Full test suite run + verification

- [ ] **Step 1: Run all tests**

```bash
lando php vendor/bin/phpunit --testdox
```

Expected: All tests pass.

- [ ] **Step 2: Verify routes**

```bash
lando php bin/console debug:router | grep -E "npc|consume"
```

Expected output:
```
api_market_consume               POST   /api/market/consume
admin_npc_clubs_content          GET    /admin/npc-clubs/content
admin_npc_clubs_generate         POST   /admin/npc-clubs/generate
admin_npc_clubs_replenish_senior POST   /admin/npc-clubs/replenish-senior
```

- [ ] **Step 3: Verify schema is current**

```bash
lando php bin/console doctrine:schema:validate
```

Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.`

- [ ] **Step 4: Smoke test the admin**

```bash
lando php bin/console cache:clear
```

Navigate the admin:
- `Clubs & Leagues > NPC Clubs` — CRUD list renders
- `Clubs & Leagues > Generate` — quick action page renders with Generate Clubs and Replenish Senior Pool cards
- `Configuration > Pool Config` — Senior Player card is visible with 5 new fields

- [ ] **Step 5: Final commit**

```bash
git add -p  # review any remaining changes
git commit -m "chore: final wiring and verification for NPC club generation (Spec A)"
```

---

## Self-Review Against Spec

| Spec requirement | Covered in task |
|---|---|
| `NpcClub` entity with all fields | Task 3 |
| No FK to Player/Staff | Task 3 — no FK defined |
| `facilities` JSON flat map | Task 3, 7 |
| Facility levels preset by tier band | Task 7 |
| `StaffRole` — MANAGER, DOF, FACILITY_MANAGER | Task 1 |
| `FacilityTemplate.category` — STADIUM added | Task 1 |
| `PoolConfig` — 5 senior player fields | Task 2 |
| `MarketPoolService::generateSeniorPlayers()` | Task 6 |
| No guardians for senior players | Task 6 |
| `replenishPool()` checks senior target | Task 6 |
| `NpcClubGenerationService::generateClubs()` | Task 7 |
| Hybrid naming (PLACE_NAMES + SUFFIXES) | Task 7 |
| Reputation/balance scaled by tier | Task 7 |
| `POST /api/market/consume` | Task 8 |
| Idempotent — unknown IDs silently ignored | Task 8 |
| Response `{deleted: {players, staff, scouts}}` | Task 8 |
| `NpcClubCrudController` (List/Edit/Create) | Task 9 |
| Admin page `/admin/npc-clubs/generate` | Task 10, 12 |
| Generate Clubs action card | Task 12 |
| Replenish Senior Pool action card | Task 12 |
| Pool Config page — senior fields | Task 11 |
| Sidebar section `Clubs & Leagues` | Task 10 |
| Migration for all schema changes | Task 4 |
