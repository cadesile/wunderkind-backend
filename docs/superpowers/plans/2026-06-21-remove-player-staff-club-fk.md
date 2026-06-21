# Remove Player/Staff Club FK Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove `club_id` FK from `Player` and `Staff` entities, deleting pool entities on consumption instead of assigning them, eliminating all Doctrine cascade problems.

**Architecture:** Pool entities (Player, Staff) exist in the DB until consumed. On assign (`POST /api/market/assign`) or starter pack initialization, the backend builds a snapshot array, removes the entity via `em->remove()`, flushes, and returns the snapshot. The frontend stores the snapshot locally. Club.players and Club.staff OneToMany collections are removed from the ORM entirely, ending all cascade issues.

**Tech Stack:** PHP 8.4, Symfony, Doctrine ORM 3, PostgreSQL 16 in Lando container, PHPUnit

## Global Constraints

- All PHP commands must run inside Lando: `lando php ...`
- Never commit directly to `master` — use branch `feat/remove-player-staff-club-fk`
- Sponsor and Investor entities retain their `club` FK — do NOT touch them
- Scout entity has no `club` FK already — no changes needed to Scout entity
- Transfer entity records history and is untouched
- Frontend contracts unchanged — no API contract changes beyond the return value of `POST /api/market/assign` for Player/Staff (now returns `{ success: true, entityId: "...", snapshot: {...} }`)

---

### Task 1: Entity layer — Player, Staff, Club, PlayerGenerationService

**Files:**
- Modify: `src/Entity/Player.php`
- Modify: `src/Entity/Staff.php`
- Modify: `src/Entity/Club.php`
- Modify: `src/Service/PlayerGenerationService.php`
- Create: `tests/Entity/PoolEntityTest.php`

**Interfaces:**
- Produces: `Player` constructor no longer accepts `?Club $club`; no `getClub()`, `setClub()`, `isInMarketPool()`, `isAssigned()`, `isAgeOutWarningIssued()`, `isForcedSaleExecuted()`, `getForcedSaleWeek()` methods; no `ageOutWarningIssued`, `forcedSaleExecuted`, `forcedSaleWeek`, `club` fields
- Produces: `Staff` constructor no longer accepts `?Club $club`; no `getClub()`, `setClub()`, `isInMarketPool()`, `isAssigned()` methods; no `club` field
- Produces: `Club` has no `getPlayers()`, `getStaff()` methods; no `players`, `staff` fields
- Later tasks depend on these interfaces — every task in this plan that references Player/Staff/Club uses only the methods that remain after Task 1

- [ ] **Step 1: Create the branch**

```bash
git checkout -b feat/remove-player-staff-club-fk
```

- [ ] **Step 2: Write the failing test**

Create `tests/Entity/PoolEntityTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Player;
use App\Entity\Staff;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use PHPUnit\Framework\TestCase;

class PoolEntityTest extends TestCase
{
    public function testPlayerHasNoClubMethods(): void
    {
        $this->assertFalse(method_exists(Player::class, 'getClub'));
        $this->assertFalse(method_exists(Player::class, 'setClub'));
        $this->assertFalse(method_exists(Player::class, 'isInMarketPool'));
        $this->assertFalse(method_exists(Player::class, 'isAssigned'));
        $this->assertFalse(method_exists(Player::class, 'isAgeOutWarningIssued'));
        $this->assertFalse(method_exists(Player::class, 'isForcedSaleExecuted'));
        $this->assertFalse(method_exists(Player::class, 'getForcedSaleWeek'));
    }

    public function testPlayerCanBeConstructedWithoutClub(): void
    {
        $player = new Player(
            firstName: 'John',
            lastName: 'Doe',
            nationality: 'English',
            position: PlayerPosition::MIDFIELDER,
            recruitmentSource: RecruitmentSource::YOUTH_INTAKE,
            potential: 70,
            currentAbility: 50,
        );
        $this->assertSame('John', $player->getFirstName());
    }

    public function testStaffHasNoClubMethods(): void
    {
        $this->assertFalse(method_exists(Staff::class, 'getClub'));
        $this->assertFalse(method_exists(Staff::class, 'setClub'));
        $this->assertFalse(method_exists(Staff::class, 'isInMarketPool'));
        $this->assertFalse(method_exists(Staff::class, 'isAssigned'));
    }

    public function testStaffCanBeConstructedWithoutClub(): void
    {
        $staff = new Staff(firstName: 'Jane', lastName: 'Smith', role: StaffRole::COACH);
        $this->assertSame('Jane', $staff->getFirstName());
    }
}
```

- [ ] **Step 3: Run test — confirm it fails**

```bash
lando php vendor/bin/phpunit tests/Entity/PoolEntityTest.php --no-coverage
```

Expected: FAIL — Player/Staff still have `getClub()` and related methods.

- [ ] **Step 4: Modify `src/Entity/Player.php`**

Replace the full file:

```php
<?php

namespace App\Entity;

use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use App\Repository\PlayerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Player
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateOfBirth;

    #[ORM\Column(length: 60)]
    private string $nationality;

    #[ORM\Column(enumType: PlayerPosition::class)]
    private PlayerPosition $position;

    #[ORM\Column(enumType: PlayerStatus::class)]
    private PlayerStatus $status = PlayerStatus::ACTIVE;

    #[ORM\Column(enumType: RecruitmentSource::class)]
    private RecruitmentSource $recruitmentSource;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $potential;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $currentAbility;

    /** Current contract value in pence/cents */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $contractValue = 0;

    #[ORM\Embedded(class: PersonalityProfile::class)]
    private PersonalityProfile $personality;

    #[ORM\OneToMany(mappedBy: 'player', targetEntity: Guardian::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $guardians;

    #[ORM\ManyToOne(inversedBy: 'players')]
    private ?Agent $agent = null;

    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'player_siblings')]
    private Collection $siblings;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $pace = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $technical = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $vision = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $power = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $stamina = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $heart = 0;

    /** Height in centimetres */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $height = 0;

    /** Weight in kilograms */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $weight = 0;

    /** Player morale (0–100) */
    #[ORM\Column(type: 'integer')]
    private int $morale = 50;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $firstName = '',
        string $lastName = '',
        \DateTimeImmutable $dateOfBirth = new \DateTimeImmutable(),
        string $nationality = '',
        PlayerPosition $position = PlayerPosition::MIDFIELDER,
        RecruitmentSource $recruitmentSource = RecruitmentSource::SCOUTING_NETWORK,
        int $potential = 0,
        int $currentAbility = 0,
    ) {
        $this->id                = new UuidV7();
        $this->firstName         = $firstName;
        $this->lastName          = $lastName;
        $this->dateOfBirth       = $dateOfBirth;
        $this->nationality       = $nationality;
        $this->position          = $position;
        $this->recruitmentSource = $recruitmentSource;
        $this->potential         = $potential;
        $this->currentAbility    = $currentAbility;
        $this->personality       = new PersonalityProfile();
        $this->guardians         = new ArrayCollection();
        $this->siblings          = new ArrayCollection();
        $this->createdAt         = new \DateTimeImmutable();
        $this->updatedAt         = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }

    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }

    public function getDateOfBirth(): \DateTimeImmutable { return $this->dateOfBirth; }
    public function setDateOfBirth(\DateTimeImmutable $dob): void { $this->dateOfBirth = $dob; }

    public function getAge(): int {
        return (int) $this->getDateOfBirth()->diff(new \DateTimeImmutable("now"))->y;
    }

    public function getNationality(): string { return $this->nationality; }
    public function setNationality(string $nationality): void { $this->nationality = $nationality; }

    public function getPosition(): PlayerPosition { return $this->position; }
    public function setPosition(PlayerPosition $position): void { $this->position = $position; }
    public function getPositionValue(): string { return $this->position->value; }

    public function getStatus(): PlayerStatus { return $this->status; }
    public function setStatus(PlayerStatus $status): void { $this->status = $status; }
    public function getStatusValue(): string { return $this->status->value; }

    public function getRecruitmentSource(): RecruitmentSource { return $this->recruitmentSource; }
    public function setRecruitmentSource(RecruitmentSource $source): void { $this->recruitmentSource = $source; }

    public function getPotential(): int { return $this->potential; }
    public function setPotential(int $potential): void { $this->potential = $potential; }

    public function getCurrentAbility(): int { return $this->currentAbility; }
    public function setCurrentAbility(int $ability): void { $this->currentAbility = $ability; }

    public function getContractValue(): int { return $this->contractValue; }
    public function setContractValue(int $value): void { $this->contractValue = $value; }

    public function getMorale(): int { return $this->morale; }
    public function setMorale(int $morale): void { $this->morale = max(0, min(100, $morale)); }

    public function getPace(): int { return $this->pace; }
    public function setPace(int $v): void { $this->pace = max(0, min(100, $v)); }

    public function getTechnical(): int { return $this->technical; }
    public function setTechnical(int $v): void { $this->technical = max(0, min(100, $v)); }

    public function getVision(): int { return $this->vision; }
    public function setVision(int $v): void { $this->vision = max(0, min(100, $v)); }

    public function getPower(): int { return $this->power; }
    public function setPower(int $v): void { $this->power = max(0, min(100, $v)); }

    public function getStamina(): int { return $this->stamina; }
    public function setStamina(int $v): void { $this->stamina = max(0, min(100, $v)); }

    public function getHeart(): int { return $this->heart; }
    public function setHeart(int $v): void { $this->heart = max(0, min(100, $v)); }

    public function getOverall(): int
    {
        return (int) round(($this->pace + $this->technical + $this->vision + $this->power + $this->stamina + $this->heart) / 6);
    }

    public function getHeight(): int { return $this->height; }
    public function setHeight(int $cm): void { $this->height = max(0, $cm); }

    public function getWeight(): int { return $this->weight; }
    public function setWeight(int $kg): void { $this->weight = max(0, $kg); }

    public function getPersonality(): PersonalityProfile { return $this->personality; }

    public function getGuardians(): Collection { return $this->guardians; }

    public function addGuardian(Guardian $guardian): void
    {
        if (!$this->guardians->contains($guardian)) {
            $this->guardians->add($guardian);
            $guardian->setPlayer($this);
        }
    }

    public function removeGuardian(Guardian $guardian): void
    {
        $this->guardians->removeElement($guardian);
    }

    public function getAgent(): ?Agent { return $this->agent; }
    public function setAgent(?Agent $agent): void { $this->agent = $agent; }

    public function getSiblings(): Collection { return $this->siblings; }

    public function addSibling(Player $sibling): void
    {
        if (!$this->siblings->contains($sibling)) {
            $this->siblings->add($sibling);
            $sibling->addSibling($this);
        }
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
```

- [ ] **Step 5: Modify `src/Entity/Staff.php`**

Replace the full file:

```php
<?php

namespace App\Entity;

use App\Enum\StaffRole;
use App\Repository\StaffRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: StaffRepository::class)]
class Staff
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(enumType: StaffRole::class)]
    private StaffRole $role;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $coachingAbility = 50;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $scoutingRange = 50;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $weeklySalary = 0;

    /** Staff morale (0–100) */
    #[ORM\Column(type: 'integer')]
    private int $morale = 50;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $specialty = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $specialisms = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dob = null;

    #[ORM\Column]
    private \DateTimeImmutable $hiredAt;

    public function __construct(
        string $firstName = '',
        string $lastName = '',
        StaffRole $role = StaffRole::COACH,
    ) {
        $this->id        = new UuidV7();
        $this->firstName = $firstName;
        $this->lastName  = $lastName;
        $this->role      = $role;
        $this->hiredAt   = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }

    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }

    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }

    public function getRole(): StaffRole { return $this->role; }
    public function setRole(StaffRole $role): void { $this->role = $role; }
    public function getRoleValue(): string { return $this->role->value; }

    public function getCoachingAbility(): int { return $this->coachingAbility; }
    public function setCoachingAbility(int $v): void { $this->coachingAbility = max(1, min(100, $v)); }

    public function getScoutingRange(): int { return $this->scoutingRange; }
    public function setScoutingRange(int $v): void { $this->scoutingRange = max(1, min(100, $v)); }

    public function getWeeklySalary(): int { return $this->weeklySalary; }
    public function setWeeklySalary(int $salary): void { $this->weeklySalary = $salary; }

    public function getMorale(): int { return $this->morale; }
    public function setMorale(int $morale): void { $this->morale = max(0, min(100, $morale)); }

    public function getSpecialty(): ?string { return $this->specialty; }
    public function setSpecialty(?string $specialty): void { $this->specialty = $specialty; }

    public function getSpecialisms(): ?array { return $this->specialisms; }
    public function setSpecialisms(?array $specialisms): void { $this->specialisms = $specialisms; }

    public function getSpecialismsJson(): string
    {
        return json_encode($this->specialisms ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function setSpecialismsJson(string $json): void
    {
        $decoded = json_decode($json, true);
        $this->specialisms = is_array($decoded) && !empty($decoded) ? $decoded : null;
    }

    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }

    public function getHiredAt(): \DateTimeImmutable { return $this->hiredAt; }
}
```

- [ ] **Step 6: Modify `src/Entity/Club.php`**

Remove the `players` and `staff` OneToMany collections. Apply these targeted edits (showing only the changed lines with surrounding context):

Remove the two collection fields (lines 99–103):
```php
// DELETE these two lines:
#[ORM\OneToMany(mappedBy: 'club', targetEntity: Player::class, cascade: ['persist', 'remove'])]
private Collection $players;

#[ORM\OneToMany(mappedBy: 'club', targetEntity: Staff::class, cascade: ['persist', 'remove'])]
private Collection $staff;
```

In `__construct()`, remove these two lines:
```php
// DELETE:
$this->players            = new ArrayCollection();
$this->staff              = new ArrayCollection();
```

Remove these two getter methods:
```php
// DELETE:
public function getPlayers(): Collection { return $this->players; }
public function getStaff(): Collection { return $this->staff; }
```

Remove the `Player` import and the `Staff` import if they are no longer used elsewhere in the file. (Keep `Investor`, `Sponsor`, `Transfer`, `SyncRecord`, `LeaderboardEntry`, `InboxMessage` imports — those are still used.)

- [ ] **Step 7: Modify `src/Service/PlayerGenerationService.php`**

In the `buildEntity()` method, the `Player` constructor call passes `club: null`. Remove that argument:

```php
// BEFORE (line 163):
$player = new Player(
    firstName:         $bp->firstName,
    lastName:          $bp->lastName,
    dateOfBirth:       $bp->dateOfBirth,
    nationality:       $bp->nationality,
    position:          $bp->position,
    recruitmentSource: $bp->source,
    potential:         $bp->potential,
    currentAbility:    $bp->currentAbility,
    club:              null,  // DELETE this line
);

// AFTER:
$player = new Player(
    firstName:         $bp->firstName,
    lastName:          $bp->lastName,
    dateOfBirth:       $bp->dateOfBirth,
    nationality:       $bp->nationality,
    position:          $bp->position,
    recruitmentSource: $bp->source,
    potential:         $bp->potential,
    currentAbility:    $bp->currentAbility,
);
```

- [ ] **Step 8: Run the entity test — confirm it passes**

```bash
lando php vendor/bin/phpunit tests/Entity/PoolEntityTest.php --no-coverage
```

Expected: PASS

- [ ] **Step 9: Generate the DB migration**

```bash
lando php bin/console doctrine:migrations:diff
```

This generates a migration under `migrations/`. Open the generated file and verify it drops `club_id` from `player` and `staff` tables (and drops the `idx_player_club` and `idx_staff_club` indexes). It should also reflect removal of `age_out_warning_issued`, `forced_sale_executed`, `forced_sale_week` from `player`.

Before adding the `up()` logic, prepend these data-safety statements to `up()` to clear any assigned entities from production before dropping the FK column:

```php
// Data cleanup: delete any pool entities that were assigned to clubs
// (frontend already has their snapshots; these are safe to remove)
$this->addSql('DELETE FROM guardian WHERE player_id IN (SELECT id FROM player WHERE club_id IS NOT NULL)');
$this->addSql('DELETE FROM player WHERE club_id IS NOT NULL');
$this->addSql('DELETE FROM staff WHERE club_id IS NOT NULL');
```

Place these lines at the TOP of `up()`, before any `ALTER TABLE` statements.

- [ ] **Step 10: Run the migration**

```bash
lando php bin/console doctrine:migrations:migrate --no-interaction
```

Expected: Migration runs without errors.

- [ ] **Step 11: Verify schema**

```bash
lando php bin/console doctrine:schema:validate
```

Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.`

- [ ] **Step 12: Commit**

```bash
git add src/Entity/Player.php src/Entity/Staff.php src/Entity/Club.php src/Service/PlayerGenerationService.php tests/Entity/PoolEntityTest.php migrations/
git commit -m "feat: remove club FK from Player and Staff entities"
```

---

### Task 2: Repository cleanup — PlayerRepository, StaffRepository

**Files:**
- Modify: `src/Repository/PlayerRepository.php`
- Modify: `src/Repository/StaffRepository.php`
- Create: `tests/Repository/PoolRepositoryTest.php`

**Interfaces:**
- Consumes: `Player` (no `club` field — from Task 1)
- Produces: `PlayerRepository::findInPool()` — no `club IS NULL` filter; all pool methods work without club filter
- Produces: `StaffRepository::findInPool()`, `findInPoolByRoleRandom()`, `countInPool()`, `countInPoolByNationalityAndRole()` — same, no club filter

- [ ] **Step 1: Write the failing test**

Create `tests/Repository/PoolRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\PlayerRepository;
use App\Repository\StaffRepository;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that repository query-builder strings no longer contain 'club IS NULL'.
 * Reads the source file directly — a lightweight smoke-test that no club filter snuck back in.
 */
class PoolRepositoryTest extends TestCase
{
    public function testPlayerRepositoryHasNoClubIsNullFilter(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Repository/PlayerRepository.php');
        $this->assertStringNotContainsString('club IS NULL', $src);
        $this->assertStringNotContainsString('p.club IS NULL', $src);
        $this->assertStringNotContainsString("'club'", $src, 'findBy([\'club\' ...]) reference found');
    }

    public function testStaffRepositoryHasNoClubIsNullFilter(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Repository/StaffRepository.php');
        $this->assertStringNotContainsString('club IS NULL', $src);
        $this->assertStringNotContainsString('s.club IS NULL', $src);
        $this->assertStringNotContainsString("'club'", $src, 'findBy([\'club\' ...]) reference found');
    }
}
```

- [ ] **Step 2: Run test — confirm it fails**

```bash
lando php vendor/bin/phpunit tests/Repository/PoolRepositoryTest.php --no-coverage
```

Expected: FAIL — repositories still contain `club IS NULL`.

- [ ] **Step 3: Modify `src/Repository/PlayerRepository.php`**

Apply these changes:

**`findInPool()`** — remove the `club IS NULL` where clause:
```php
// BEFORE:
$qb = $this->createQueryBuilder('p')
    ->where('p.club IS NULL')
    ->orderBy('p.createdAt', 'DESC')
    ->setMaxResults($limit);

// AFTER:
$qb = $this->createQueryBuilder('p')
    ->orderBy('p.createdAt', 'DESC')
    ->setMaxResults($limit);
```

**`countInPool()`** — remove where clause:
```php
// BEFORE:
return (int) $this->createQueryBuilder('p')
    ->select('COUNT(p.id)')
    ->where('p.club IS NULL')
    ->getQuery()
    ->getSingleScalarResult();

// AFTER:
return (int) $this->createQueryBuilder('p')
    ->select('COUNT(p.id)')
    ->getQuery()
    ->getSingleScalarResult();
```

**`countInPoolByNationality()`** — change `where` to `where` on nationality only:
```php
// BEFORE:
return (int) $this->createQueryBuilder('p')
    ->select('COUNT(p.id)')
    ->where('p.club IS NULL')
    ->andWhere('p.nationality = :nat')
    ->setParameter('nat', $nationality)
    ->getQuery()
    ->getSingleScalarResult();

// AFTER:
return (int) $this->createQueryBuilder('p')
    ->select('COUNT(p.id)')
    ->where('p.nationality = :nat')
    ->setParameter('nat', $nationality)
    ->getQuery()
    ->getSingleScalarResult();
```

**Delete `findByClub()` method entirely** (lines 71–74).

**Delete `findActiveByClub()` method entirely** (lines 81–94).

**`findForWorldInitByPosition()`** — remove `p.club IS NULL` where:
```php
// BEFORE:
return $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.club IS NULL')
    ->andWhere('p.currentAbility BETWEEN :min AND :max')
    ->andWhere('p.position = :position')
    ...

// AFTER:
return $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.currentAbility BETWEEN :min AND :max')
    ->andWhere('p.position = :position')
    ...
```

**`findForWorldInit()`** — same, remove `p.club IS NULL` where:
```php
// BEFORE:
return $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.club IS NULL')
    ->andWhere('p.currentAbility BETWEEN :min AND :max')
    ...

// AFTER:
return $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.currentAbility BETWEEN :min AND :max')
    ...
```

**`findForWorldInitByPositionAndNationality()`** — remove `p.club IS NULL` where:
```php
// BEFORE:
$qb = $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.club IS NULL')
    ->andWhere('p.currentAbility BETWEEN :min AND :max')
    ...

// AFTER:
$qb = $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.currentAbility BETWEEN :min AND :max')
    ...
```

**`findForeignForWorldInitByPosition()`** — remove `p.club IS NULL` where:
```php
// BEFORE:
$qb = $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.club IS NULL')
    ->andWhere('p.currentAbility BETWEEN :min AND :max')
    ...

// AFTER:
$qb = $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.currentAbility BETWEEN :min AND :max')
    ...
```

**`findForeignForWorldInit()`** — same:
```php
// BEFORE:
return $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.club IS NULL')
    ->andWhere('p.currentAbility BETWEEN :min AND :max')
    ...

// AFTER:
return $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.currentAbility BETWEEN :min AND :max')
    ...
```

**`findForScoutSearch()`** — remove `p.club IS NULL` where:
```php
// BEFORE:
$qb = $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.club IS NULL')
    ->andWhere('p.currentAbility BETWEEN :abilityMin AND :abilityMax')
    ...

// AFTER:
$qb = $this->createQueryBuilder('p')
    ->addSelect('RAND() AS HIDDEN rand_order')
    ->where('p.currentAbility BETWEEN :abilityMin AND :abilityMax')
    ...
```

Also remove the `use App\Entity\Club;` import from the top of the file (no longer referenced).

- [ ] **Step 4: Modify `src/Repository/StaffRepository.php`**

**`findInPool()`** — remove `s.club IS NULL` where clause:
```php
// BEFORE:
$qb = $this->createQueryBuilder('s')
    ->where('s.club IS NULL');

if ($role !== null) {
    $qb->andWhere('s.role = :role')->setParameter('role', $role);
}
...

// AFTER:
$qb = $this->createQueryBuilder('s');

if ($role !== null) {
    $qb->andWhere('s.role = :role')->setParameter('role', $role);
}
...
```

**`countInPool()`** — remove `s.club IS NULL` where:
```php
// BEFORE:
$qb = $this->createQueryBuilder('s')
    ->select('COUNT(s.id)')
    ->where('s.club IS NULL');

// AFTER:
$qb = $this->createQueryBuilder('s')
    ->select('COUNT(s.id)');
```

**`countInPoolByNationalityAndRole()`** — change `where('s.club IS NULL')` to `where('s.nationality = :nat')`:
```php
// BEFORE:
return (int) $this->createQueryBuilder('s')
    ->select('COUNT(s.id)')
    ->where('s.club IS NULL')
    ->andWhere('s.nationality = :nat')
    ->andWhere('s.role = :role')
    ->setParameter('nat', $nationality)
    ->setParameter('role', $role)
    ->getQuery()
    ->getSingleScalarResult();

// AFTER:
return (int) $this->createQueryBuilder('s')
    ->select('COUNT(s.id)')
    ->where('s.nationality = :nat')
    ->andWhere('s.role = :role')
    ->setParameter('nat', $nationality)
    ->setParameter('role', $role)
    ->getQuery()
    ->getSingleScalarResult();
```

**Delete `findByClub()` method entirely.**

**`findInPoolByRoleRandom()`** — remove `s.club IS NULL` where:
```php
// BEFORE:
$qb = $this->createQueryBuilder('s')
    ->where('s.club IS NULL')
    ->andWhere('s.role = :role')
    ->setParameter('role', $role);

// AFTER:
$qb = $this->createQueryBuilder('s')
    ->where('s.role = :role')
    ->setParameter('role', $role);
```

Also remove the `use App\Entity\Club;` import from the top of the file.

- [ ] **Step 5: Run test — confirm it passes**

```bash
lando php vendor/bin/phpunit tests/Repository/PoolRepositoryTest.php --no-coverage
```

Expected: PASS

- [ ] **Step 6: Run full test suite**

```bash
lando php vendor/bin/phpunit --no-coverage
```

Expected: all previously passing tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Repository/PlayerRepository.php src/Repository/StaffRepository.php tests/Repository/PoolRepositoryTest.php
git commit -m "feat: remove club IS NULL filters from pool repositories"
```

---

### Task 3: StarterPackService — consume pool entities on assign

**Files:**
- Modify: `src/Service/StarterPackService.php`
- Modify: `tests/Service/StarterPackServicePrewarmTest.php` (complete rewrite)

**Interfaces:**
- Consumes: `Player` (no `setClub()` — from Task 1), `Staff` (same), `WorldInitializationService::buildPlayerSnapshot()`, `WorldInitializationService::buildStaffSnapshot()`, `WorldInitializationService::buildScoutSnapshot()`
- Produces: `StarterPackService::initialize(Club): array` — unchanged signature; now removes consumed Player/Staff from DB instead of setting club FK; MarketPoolService no longer injected

- [ ] **Step 1: Write the failing test**

Replace `tests/Service/StarterPackServicePrewarmTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Entity\StarterConfig;
use App\Enum\PlayerPosition;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StarterPackServiceTest extends TestCase
{
    public function testInitializeDeletesConsumedPlayersAndStaff(): void
    {
        $config = $this->createMock(StarterConfig::class);
        $config->method('getStarterPlayerCount')->willReturn(2);
        $config->method('getStarterCoachCount')->willReturn(1);
        $config->method('getStarterManagerCount')->willReturn(0);
        $config->method('getStarterChairmanCount')->willReturn(0);
        $config->method('getStarterDirectorOfFootballCount')->willReturn(0);
        $config->method('getStarterFacilityManagerCount')->willReturn(0);
        $config->method('getStarterScoutCount')->willReturn(1);
        $config->method('getLeagueAbilityRanges')->willReturn([]);

        $starterConfigRepo = $this->createMock(StarterConfigRepository::class);
        $starterConfigRepo->method('getConfig')->willReturn($config);

        $club = $this->createMock(Club::class);
        $club->method('getCountry')->willReturn('EN');
        $club->method('getCurrentLeague')->willReturn(null);
        $club->method('isStarterInitialized')->willReturn(false);

        // Two mock Player entities in the pool
        $player1 = $this->createMock(Player::class);
        $player2 = $this->createMock(Player::class);

        $playerRepo = $this->createMock(PlayerRepository::class);
        $playerRepo->method('findForWorldInitByPositionAndNationality')->willReturnOnConsecutiveCalls(
            [$player1],
            [$player2],
        );
        $playerRepo->method('findForeignForWorldInitByPosition')->willReturn([]);

        $staff1 = $this->createMock(Staff::class);
        $staffRepo = $this->createMock(StaffRepository::class);
        $staffRepo->method('findInPoolByRoleRandom')->willReturn([$staff1]);

        $scout1 = $this->createMock(Scout::class);
        $scoutRepo = $this->createMock(ScoutRepository::class);
        $scoutRepo->method('findInPool')->willReturn([$scout1]);

        $poolConfig = $this->createMock(\App\Entity\PoolConfig::class);
        $poolConfig->method('getPositionWeightGk')->willReturn(1);
        $poolConfig->method('getPositionWeightDef')->willReturn(1);
        $poolConfig->method('getPositionWeightMid')->willReturn(0);
        $poolConfig->method('getPositionWeightAtt')->willReturn(0);
        $poolConfigRepo = $this->createMock(PoolConfigRepository::class);
        $poolConfigRepo->method('getConfig')->willReturn($poolConfig);

        $worldInit = $this->createMock(WorldInitializationService::class);
        $worldInit->method('distributeByPosition')->willReturn([
            'GK'  => 1,
            'DEF' => 1,
        ]);
        $worldInit->method('buildPlayerSnapshot')->willReturn(['id' => 'player-uuid']);
        $worldInit->method('buildStaffSnapshot')->willReturn(['id' => 'staff-uuid']);
        $worldInit->method('buildScoutSnapshot')->willReturn(['id' => 'scout-uuid']);

        // Key assertion: em->remove() must be called for each consumed Player and Staff
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(3)) // 2 players + 1 staff
            ->method('remove')
            ->with($this->logicalOr(
                $this->identicalTo($player1),
                $this->identicalTo($player2),
                $this->identicalTo($staff1),
            ));
        $em->expects($this->atLeastOnce())->method('flush');

        $service = new StarterPackService(
            $playerRepo,
            $staffRepo,
            $scoutRepo,
            $starterConfigRepo,
            $poolConfigRepo,
            $worldInit,
            $em,
        );

        $result = $service->initialize($club);
        $this->assertArrayHasKey('players', $result);
        $this->assertArrayHasKey('staff', $result);
        $this->assertArrayHasKey('scouts', $result);
    }
}
```

- [ ] **Step 2: Run test — confirm it fails**

```bash
lando php vendor/bin/phpunit tests/Service/StarterPackServiceTest.php --no-coverage
```

Expected: FAIL — constructor still has 8 parameters, setClub still called, no remove() called.

- [ ] **Step 3: Rewrite `src/Service/StarterPackService.php`**

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
use App\Enum\StaffRole;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
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
    ) {}

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

        $ampScouts = $this->scoutRepository->findInPool($starterConfig->getStarterScoutCount(), nationality: $ampNationality);
        if (count($ampScouts) < $starterConfig->getStarterScoutCount()) {
            $deficit   = $starterConfig->getStarterScoutCount() - count($ampScouts);
            $ampScouts = array_merge($ampScouts, $this->scoutRepository->findInPool($deficit));
        }
        $ampScouts = array_values(array_unique($ampScouts, SORT_REGULAR));

        // Build snapshots before deletion (entities must exist to serialise)
        $playerSnapshots = array_map(
            fn(Player $p) => $this->worldInitializationService->buildPlayerSnapshot($p),
            $ampPlayers
        );
        $staffSnapshots = array_map(
            fn(Staff $s) => $this->worldInitializationService->buildStaffSnapshot($s),
            $ampStaff
        );
        $scoutSnapshots = array_map(
            fn(Scout $s) => $this->worldInitializationService->buildScoutSnapshot($s),
            $ampScouts
        );

        // Consume pool entities — delete from DB, frontend stores snapshots locally
        foreach ($ampPlayers as $p) { $this->em->remove($p); }
        foreach ($ampStaff   as $s) { $this->em->remove($s); }

        $club->setStarterInitializedAt(new \DateTimeImmutable());
        $this->em->flush();

        return [
            'players' => $playerSnapshots,
            'staff'   => $staffSnapshots,
            'scouts'  => $scoutSnapshots,
        ];
    }

    private function fillStaffRole(StaffRole $role, int $limit, string $nationality): array
    {
        if ($limit <= 0) return [];
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

- [ ] **Step 4: Run test — confirm it passes**

```bash
lando php vendor/bin/phpunit tests/Service/StarterPackServiceTest.php --no-coverage
```

Expected: PASS

- [ ] **Step 5: Run full suite**

```bash
lando php vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Service/StarterPackService.php tests/Service/StarterPackServiceTest.php
git commit -m "feat: StarterPackService deletes consumed pool entities instead of assigning to club"
```

---

### Task 4: MarketPoolService — assignToClub() deletes Player/Staff, returns snapshot

**Files:**
- Modify: `src/Service/MarketPoolService.php`
- Create: `tests/Service/MarketPoolAssignTest.php`

**Interfaces:**
- Consumes: `Player` (no `isInMarketPool()`, no `setClub()` — Task 1), `Staff` (same), `WorldInitializationService::buildPlayerSnapshot()`, `WorldInitializationService::buildStaffSnapshot()`
- Produces: `MarketPoolService::assignToClub(mixed $entity, Club $club): array|null` — returns snapshot array for Player/Staff; null for Scout/Sponsor/Investor
- Consumes: `WorldInitializationService` (injected as new constructor parameter)

- [ ] **Step 1: Write the failing test**

Create `tests/Service/MarketPoolAssignTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Sponsor;
use App\Entity\Staff;
use App\Repository\AgentRepository;
use App\Repository\InvestorRepository;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\SponsorRepository;
use App\Repository\StaffRepository;
use App\Service\MarketPoolService;
use App\Service\NameGeneratorService;
use App\Service\PlayerGenerationService;
use App\Service\StaffGenerationService;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class MarketPoolAssignTest extends TestCase
{
    private function makeService(EntityManagerInterface $em, WorldInitializationService $worldInit): MarketPoolService
    {
        return new MarketPoolService(
            $this->createMock(PlayerRepository::class),
            $this->createMock(StaffRepository::class),
            $this->createMock(ScoutRepository::class),
            $this->createMock(SponsorRepository::class),
            $this->createMock(InvestorRepository::class),
            $this->createMock(AgentRepository::class),
            $this->createMock(PoolConfigRepository::class),
            $this->createMock(PlayerGenerationService::class),
            $this->createMock(StaffGenerationService::class),
            $this->createMock(NameGeneratorService::class),
            $em,
            $worldInit,
        );
    }

    public function testPlayerAssignDeletesEntityAndReturnsSnapshot(): void
    {
        $player  = $this->createMock(Player::class);
        $club    = $this->createMock(Club::class);
        $snapshot = ['id' => 'uuid-player', 'firstName' => 'Test'];

        $worldInit = $this->createMock(WorldInitializationService::class);
        $worldInit->expects($this->once())
            ->method('buildPlayerSnapshot')
            ->with($player)
            ->willReturn($snapshot);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($player);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($em, $worldInit);
        $result  = $service->assignToClub($player, $club);

        $this->assertSame($snapshot, $result);
    }

    public function testStaffAssignDeletesEntityAndReturnsSnapshot(): void
    {
        $staff    = $this->createMock(Staff::class);
        $club     = $this->createMock(Club::class);
        $snapshot = ['id' => 'uuid-staff', 'role' => 'coach'];

        $worldInit = $this->createMock(WorldInitializationService::class);
        $worldInit->expects($this->once())
            ->method('buildStaffSnapshot')
            ->with($staff)
            ->willReturn($snapshot);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($staff);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($em, $worldInit);
        $result  = $service->assignToClub($staff, $club);

        $this->assertSame($snapshot, $result);
    }

    public function testScoutAssignIsNoOpAndReturnsNull(): void
    {
        $scout = $this->createMock(Scout::class);
        $club  = $this->createMock(Club::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $service = $this->makeService($em, $this->createMock(WorldInitializationService::class));
        $result  = $service->assignToClub($scout, $club);

        $this->assertNull($result);
    }

    public function testSponsorAssignSetsClubAndReturnsNull(): void
    {
        $sponsor = $this->createMock(Sponsor::class);
        $sponsor->method('isInMarketPool')->willReturn(true);
        $sponsor->expects($this->once())->method('setClub');
        $sponsor->expects($this->once())->method('setAssignedAt');

        $club = $this->createMock(Club::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($em, $this->createMock(WorldInitializationService::class));
        $result  = $service->assignToClub($sponsor, $club);

        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run test — confirm it fails**

```bash
lando php vendor/bin/phpunit tests/Service/MarketPoolAssignTest.php --no-coverage
```

Expected: FAIL — constructor doesn't accept `WorldInitializationService`; Player/Staff branches still call `setClub`.

> **Note:** The `makeService()` constructor call in the test must match the actual `MarketPoolService` constructor exactly. If `MarketPoolService` has different constructor arguments (e.g. no `StaffGenerationService` or `NameGeneratorService`), adjust the test mock list to match. Run `grep -n "public function __construct" src/Service/MarketPoolService.php` to see the current constructor signature.

- [ ] **Step 3: Modify `src/Service/MarketPoolService.php`**

**3a. Add `WorldInitializationService` to the constructor:**

Add at the end of the constructor parameter list:
```php
private readonly WorldInitializationService $worldInitializationService,
```

Add the `use` import at the top of the file:
```php
use App\Service\WorldInitializationService;
```

**3b. Rewrite the `assignToClub()` method:**

```php
/**
 * Assign a pool entity to a club.
 *
 * Player and Staff: builds a snapshot, deletes the entity from the pool, and returns the snapshot.
 * Scout: no-op (global reference pool, frontend tracks locally). Returns null.
 * Sponsor/Investor: sets club FK and assignedAt. Returns null.
 *
 * @return array|null Snapshot for Player/Staff; null for all other types.
 * @throws \RuntimeException if a Sponsor/Investor is already assigned
 */
public function assignToClub(mixed $entity, Club $club): array|null
{
    $now = new \DateTimeImmutable();

    if ($entity instanceof Player) {
        $snapshot = $this->worldInitializationService->buildPlayerSnapshot($entity);
        $this->em->remove($entity);
        $this->em->flush();
        return $snapshot;
    }

    if ($entity instanceof Staff) {
        $snapshot = $this->worldInitializationService->buildStaffSnapshot($entity);
        $this->em->remove($entity);
        $this->em->flush();
        return $snapshot;
    }

    if ($entity instanceof Scout) {
        return null;
    }

    if ($entity instanceof Sponsor) {
        if (!$entity->isInMarketPool()) {
            throw new \RuntimeException('Sponsor is already assigned to a club.');
        }
        $entity->setClub($club);
        $entity->setAssignedAt($now);
        $this->em->flush();
        return null;
    }

    if ($entity instanceof Investor) {
        if (!$entity->isInMarketPool()) {
            throw new \RuntimeException('Investor is already assigned to a club.');
        }
        $entity->setClub($club);
        $entity->setAssignedAt($now);
        $this->em->flush();
        return null;
    }

    throw new \InvalidArgumentException('Unknown entity type for assignment.');
}
```

- [ ] **Step 4: Run test — confirm it passes**

```bash
lando php vendor/bin/phpunit tests/Service/MarketPoolAssignTest.php --no-coverage
```

Expected: PASS

- [ ] **Step 5: Run full suite**

```bash
lando php vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Service/MarketPoolService.php tests/Service/MarketPoolAssignTest.php
git commit -m "feat: MarketPoolService.assignToClub deletes Player/Staff and returns snapshot"
```

---

### Task 5: EconomicService + SyncService cleanup

**Files:**
- Modify: `src/Service/EconomicService.php`
- Modify: `src/Service/SyncService.php`

**Interfaces:**
- Consumes: `Player` (no `getClub()`, no `isAgeOutWarningIssued()`, no `isForcedSaleExecuted()` — Task 1), `Club` (no `getPlayers()` — Task 1)
- `EconomicService::checkAgeOutPlayers()` is deleted — callers must be updated in this task

- [ ] **Step 1: Modify `src/Service/EconomicService.php`**

**1a. Fix `calculatePlayerMarketValue()` (line 110).**

The `$player->getClub()?->getReputation()` reference must go. Pool players have no club, so the reputation factor is always 1:

```php
// BEFORE (line 110):
$reputationFactor = 1 + $player->getClub()?->getReputation() / 1000 ?? 0;

// AFTER:
$reputationFactor = 1;
```

**1b. Delete `checkAgeOutPlayers()` method entirely** (lines 187–243 in the original file). The whole method block:

```php
public function checkAgeOutPlayers(Club $club, int $currentWeek, \DateTimeImmutable $clientTimestamp): void
{
    // ... entire method body ...
}
```

**1c. Delete the two private helper methods that only served `checkAgeOutPlayers()`:**

```php
private function calculateAge(\DateTimeImmutable $dob, \DateTimeImmutable $currentDate): int { ... }
private function weeksUntilAge21(\DateTimeImmutable $dob, \DateTimeImmutable $currentDate): int { ... }
```

**1d. Remove unused imports.** After the above deletions, check whether `PlayerStatus` is still used elsewhere in `EconomicService`. If `PlayerStatus` was only used in `checkAgeOutPlayers()`, remove it from the `use` block. Also check if `Transfer` is still needed (it was used in `checkAgeOutPlayers()` to remove transfers). Remove `LoggerInterface` if it is only used in the deleted method.

Run:
```bash
lando php bin/console cache:clear && lando php vendor/bin/phpunit --no-coverage
```
to find any remaining compile errors from unused-but-referenced imports.

- [ ] **Step 2: Modify `src/Service/SyncService.php`**

**2a. Remove `checkAgeOutPlayers()` call** (line 169):

```php
// BEFORE (around line 169):
$this->economicService->checkAgeOutPlayers($club, $request->weekNumber, $clientTimestamp);

// DELETE this line entirely.
```

**2b. Fix `processPlayerUpdates()` — remove club ownership guard** (line 414):

```php
// BEFORE:
$player = $this->em->getRepository(Player::class)->find($data['playerId']);
if ($player === null || $player->getClub() !== $club) {
    continue;
}

// AFTER:
$player = $this->em->getRepository(Player::class)->find($data['playerId']);
if ($player === null) {
    continue;
}
```

**2c. Fix `processTransfers()` — remove club ownership guard on player status update** (around line 662):

```php
// BEFORE:
if ($player !== null && $player->getClub() === $club) {
    $player->setStatus(
        $type === TransferType::AGENT_ASSISTED
            ? PlayerStatus::TRANSFERRED_VIA_AGENT
            : PlayerStatus::TRANSFERRED
    );
}

// AFTER:
if ($player !== null) {
    $player->setStatus(
        $type === TransferType::AGENT_ASSISTED
            ? PlayerStatus::TRANSFERRED_VIA_AGENT
            : PlayerStatus::TRANSFERRED
    );
}
```

- [ ] **Step 3: Clear cache and run full test suite**

```bash
lando php bin/console cache:clear
lando php vendor/bin/phpunit --no-coverage
```

Expected: all tests pass, no PHP errors.

- [ ] **Step 4: Commit**

```bash
git add src/Service/EconomicService.php src/Service/SyncService.php
git commit -m "feat: remove age-out logic and club ownership checks from EconomicService and SyncService"
```

---

### Task 6: Controller cleanup — delete SquadController, StaffController; update ClubController and MarketController

**Files:**
- Delete: `src/Controller/Api/SquadController.php`
- Delete: `src/Controller/Api/StaffController.php`
- Modify: `src/Controller/Api/ClubController.php`
- Modify: `src/Controller/Api/MarketController.php`

**Interfaces:**
- Consumes: `MarketPoolService::assignToClub()` returning `array|null` — Task 4
- Produces: `POST /api/market/assign` returns `{ success: true, entityId: "...", snapshot: {...} }` for Player/Staff; `{ success: true, entityId: "..." }` for Scout/Sponsor/Investor
- Produces: `GET /api/club/status` no longer includes `playerCount` or `staffCount`
- Removes: `GET /api/squad`, `POST /api/squad/release/{id}`, `GET /api/staff`

- [ ] **Step 1: Delete SquadController**

```bash
rm src/Controller/Api/SquadController.php
```

- [ ] **Step 2: Delete StaffController**

```bash
rm src/Controller/Api/StaffController.php
```

- [ ] **Step 3: Modify `src/Controller/Api/ClubController.php`**

Remove the two count lines from the `status()` response (around lines 204–205):

```php
// DELETE these two lines:
'playerCount'         => $club->getPlayers()->count(),
'staffCount'          => $club->getStaff()->count(),
```

The `status()` response will still include `id`, `name`, `abbreviation`, `balance`, `hasDebt`, `reputation`, `weekNumber`, `totalCareerEarnings`, `hallOfFamePoints`, `activeSponsors`, `activeInvestors`.

- [ ] **Step 4: Modify `src/Controller/Api/MarketController.php`**

**4a. Fix the pool-availability check** — `isInMarketPool()` no longer exists on Player or Staff. All pool entities are always available since there is no club assignment. Change lines 91–98:

```php
// BEFORE:
$inPool = match (true) {
    $entity instanceof Player   => $entity->isInMarketPool(),
    $entity instanceof Staff    => $entity->isInMarketPool(),
    $entity instanceof Sponsor  => $entity->isInMarketPool(),
    $entity instanceof Investor => $entity->isInMarketPool(),
    $entity instanceof Scout    => true,
    default                     => false,
};

// AFTER:
$inPool = match (true) {
    $entity instanceof Player   => true,
    $entity instanceof Staff    => true,
    $entity instanceof Sponsor  => $entity->isInMarketPool(),
    $entity instanceof Investor => $entity->isInMarketPool(),
    $entity instanceof Scout    => true,
    default                     => false,
};
```

**4b. Return the snapshot for Player/Staff.** The `assignToClub()` call now returns `array|null`. Capture it and include in the response:

```php
// BEFORE:
try {
    $pool->assignToClub($entity, $club);
} catch (\RuntimeException $e) {
    return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
}

return $this->json(['success' => true, 'entityId' => $dto->entityId]);

// AFTER:
try {
    $snapshot = $pool->assignToClub($entity, $club);
} catch (\RuntimeException $e) {
    return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
}

$response = ['success' => true, 'entityId' => $dto->entityId];
if ($snapshot !== null) {
    $response['snapshot'] = $snapshot;
}

return $this->json($response);
```

- [ ] **Step 5: Clear cache and run full test suite**

```bash
lando php bin/console cache:clear
lando php vendor/bin/phpunit --no-coverage
```

Expected: all tests pass. No references to deleted controllers remain.

- [ ] **Step 6: Verify routes are gone**

```bash
lando php bin/console debug:router | grep -E "squad|staff"
```

Expected: no `api_squad_*` or `api_staff_*` routes appear.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Api/ClubController.php src/Controller/Api/MarketController.php
git rm src/Controller/Api/SquadController.php src/Controller/Api/StaffController.php
git commit -m "feat: remove squad/staff endpoints, update market assign to return snapshot"
```

---

### Task 7: Admin dashboard + commands cleanup

**Files:**
- Modify: `src/Controller/Admin/DashboardController.php`
- Modify: `src/Command/CleanupAssignedEntitiesCommand.php`
- Modify: `src/Command/WarmPoolCommand.php`

**Interfaces:**
- Consumes: `Club` (no `getPlayers()` — Task 1)
- `checkAgeOutPlayers()` deleted — Task 5

- [ ] **Step 1: Modify `src/Controller/Admin/DashboardController.php`**

There are multiple locations to update. Make all changes below:

**1a. Remove `assignedPlayers`/`assignedStaff` stats** (around lines 87–90). Change:
```php
// BEFORE:
'poolPlayers'     => (int) $conn->fetchOne('SELECT COUNT(*) FROM player WHERE club_id IS NULL'),
'assignedPlayers' => (int) $conn->fetchOne('SELECT COUNT(*) FROM player WHERE club_id IS NOT NULL'),
'poolStaff'       => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff WHERE club_id IS NULL'),
'assignedStaff'   => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff WHERE club_id IS NOT NULL'),

// AFTER:
'poolPlayers' => (int) $conn->fetchOne('SELECT COUNT(*) FROM player'),
'poolStaff'   => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff'),
```

**1b. Remove all `WHERE club_id IS NULL` from player/staff pool count queries.** Every instance of:
- `"SELECT COUNT(*) FROM player WHERE club_id IS NULL AND ..."`  → remove `club_id IS NULL AND `
- `"SELECT COUNT(*) FROM staff WHERE club_id IS NULL AND ..."` → remove `club_id IS NULL AND `
- `"SELECT COUNT(*) FROM player WHERE club_id IS NULL"` → `'SELECT COUNT(*) FROM player'`
- `"SELECT COUNT(*) FROM staff WHERE club_id IS NULL"` → `'SELECT COUNT(*) FROM staff'`
- `"SELECT nationality, COUNT(*) AS cnt FROM player WHERE club_id IS NULL GROUP BY ..."` → remove `WHERE club_id IS NULL `
- `"SELECT position, COUNT(*) AS cnt FROM player WHERE club_id IS NULL GROUP BY ..."` → same
- `"SELECT ... FROM player WHERE club_id IS NULL GROUP BY age ..."` → remove WHERE clause

This affects lines approximately 72, 75, 78, 87-90, 551-558, 696-705, 742-751. Search in the file and replace every occurrence:

```bash
grep -n "club_id IS NULL\|club_id IS NOT NULL" src/Controller/Admin/DashboardController.php
```

Then remove `WHERE club_id IS NULL` or `AND club_id IS NULL` from each matching line in the player/staff queries. (Sponsor/Investor `WHERE club_id IS NULL` queries are untouched — those entities retain their FK.)

**1c. Fix the pool reset queries** (around lines 767–770). Change:
```php
// BEFORE:
$conn->executeStatement('DELETE FROM guardian WHERE player_id IN (SELECT id FROM player WHERE club_id IS NULL)');
$players   = $conn->executeStatement('DELETE FROM player WHERE club_id IS NULL');
$staff     = $conn->executeStatement('DELETE FROM staff WHERE club_id IS NULL');

// AFTER:
$conn->executeStatement('DELETE FROM guardian WHERE player_id IN (SELECT id FROM player)');
$players   = $conn->executeStatement('DELETE FROM player');
$staff     = $conn->executeStatement('DELETE FROM staff');
```

**1d. Remove the `checkAgeOutPlayers` block** (around lines 1222–1225). Find and delete:
```php
$playersBefore = $club->getPlayers()->count();
$economicService->checkAgeOutPlayers($club, $club->getLastSyncedWeek(), new \DateTimeImmutable());
$deletedCount  += max(0, $playersBefore - $club->getPlayers()->count());
```

These lines reference both `getPlayers()` (removed from Club) and `checkAgeOutPlayers()` (removed from EconomicService). Delete the entire surrounding admin route/action that contains this block if it exists solely for this purpose. If the route does other things, just delete these three lines.

- [ ] **Step 2: Modify `src/Command/CleanupAssignedEntitiesCommand.php`**

Remove the Player and Staff cleanup branches. They reference `p.assignedAt` / `s.assignedAt` (fields that don't exist) and Player/Staff entities are now consumed immediately on assign. Keep only the Sponsor and Investor cleanup.

Replace the full `execute()` method body:

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $io     = new SymfonyStyle($input, $output);
    $cutoff = new \DateTimeImmutable('-52 weeks');

    $io->title('Cleaning up assigned market entities older than 52 weeks');
    $io->info('Cutoff: ' . $cutoff->format('Y-m-d H:i:s'));

    // Sponsors — bulk DQL
    $deletedSponsors = $this->em->createQueryBuilder()
        ->delete(Sponsor::class, 's')
        ->where('s.assignedAt IS NOT NULL')
        ->andWhere('s.assignedAt < :cutoff')
        ->setParameter('cutoff', $cutoff)
        ->getQuery()
        ->execute();

    // Investors — bulk DQL
    $deletedInvestors = $this->em->createQueryBuilder()
        ->delete(Investor::class, 'i')
        ->where('i.assignedAt IS NOT NULL')
        ->andWhere('i.assignedAt < :cutoff')
        ->setParameter('cutoff', $cutoff)
        ->getQuery()
        ->execute();

    $total = $deletedSponsors + $deletedInvestors;

    $io->success([
        "Cleanup complete — {$total} entities removed:",
        "  Sponsors : {$deletedSponsors}",
        "  Investors: {$deletedInvestors}",
    ]);

    return Command::SUCCESS;
}
```

Also remove the `use App\Entity\Player;`, `use App\Entity\Staff;`, and `use App\Entity\Transfer;` imports from the top of the file if they are no longer referenced.

- [ ] **Step 3: Modify `src/Command/WarmPoolCommand.php`**

In `countForeignPlayers()` (around line 247–255), remove the `p.club IS NULL` condition:

```php
// BEFORE:
return (int) $this->playerRepo->createQueryBuilder('p')
    ->select('COUNT(p.id)')
    ->where('p.club IS NULL')
    ->andWhere('p.nationality NOT IN (:nats)')
    ->setParameter('nats', $exclude)
    ->getQuery()
    ->getSingleScalarResult();

// AFTER:
return (int) $this->playerRepo->createQueryBuilder('p')
    ->select('COUNT(p.id)')
    ->where('p.nationality NOT IN (:nats)')
    ->setParameter('nats', $exclude)
    ->getQuery()
    ->getSingleScalarResult();
```

In `countForeignStaff()` (around line 259–269), remove the `s.club IS NULL` condition:

```php
// BEFORE:
return (int) $this->staffRepo->createQueryBuilder('s')
    ->select('COUNT(s.id)')
    ->where('s.club IS NULL')
    ->andWhere('s.role = :role')
    ->andWhere('s.nationality NOT IN (:nats)')
    ->setParameter('role', $role)
    ->setParameter('nats', $exclude)
    ->getQuery()
    ->getSingleScalarResult();

// AFTER:
return (int) $this->staffRepo->createQueryBuilder('s')
    ->select('COUNT(s.id)')
    ->where('s.role = :role')
    ->andWhere('s.nationality NOT IN (:nats)')
    ->setParameter('role', $role)
    ->setParameter('nats', $exclude)
    ->getQuery()
    ->getSingleScalarResult();
```

`countForeignScouts()` has no `club IS NULL` condition — leave it unchanged.

- [ ] **Step 4: Clear cache and run full test suite**

```bash
lando php bin/console cache:clear
lando php vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 5: Verify no remaining references to removed methods**

```bash
grep -rn "getPlayers()\|getStaff()\|getClub()\|setClub\|isInMarketPool\|isAssigned()\|isAgeOutWarningIssued\|isForcedSaleExecuted\|getForcedSaleWeek\|checkAgeOutPlayers\|assignedPlayers\|assignedStaff\|club_id IS NULL" src/ | grep -v "Sponsor\|Investor\|FinanceController\|LeaderboardController" | grep -v "_test\|Test\."
```

Expected: no output (all references cleaned up in non-sponsor/investor context).

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Admin/DashboardController.php src/Command/CleanupAssignedEntitiesCommand.php src/Command/WarmPoolCommand.php
git commit -m "feat: remove pool/assigned distinction from admin dashboard and cleanup command"
```

---

### Final verification

- [ ] **Run the complete test suite one last time**

```bash
lando php vendor/bin/phpunit --no-coverage
```

Expected: PASS

- [ ] **Validate schema**

```bash
lando php bin/console doctrine:schema:validate
```

Expected: mapping correct, DB in sync.

- [ ] **Check for any remaining club FK references in non-Sponsor/Investor code**

```bash
grep -rn "club_id IS NULL\|club_id IS NOT NULL" src/ | grep -v "sponsor\|investor\|Sponsor\|Investor"
```

Expected: no output.
