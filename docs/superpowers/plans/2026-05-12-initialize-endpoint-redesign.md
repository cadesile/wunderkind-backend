# Initialize Endpoint Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the monolithic `POST /api/initialize` with four focused endpoints and a shared `CountryWorldPackCache` so initialization is chunked, retryable, and timeout-safe.

**Architecture:** `POST /starter` assigns AMP squad and marks step 1 done. `GET /leagues` returns lightweight league metadata. `POST /league/{tier}` generates NPC squads for one tier, stores them in `CountryWorldPackCache`, and marks the club fully initialized once all tiers are cached. A `WarmWorldPackCommand` pre-warms the country cache via CLI.

**Tech Stack:** Symfony 8, PHP 8.4, Doctrine ORM 3, PostgreSQL 16, Lando. PHPUnit is not installed — verification is done via `lando php bin/console` and curl commands.

---

## File Map

| Action | File |
|--------|------|
| Create | `src/Entity/CountryWorldPackCache.php` |
| Create | `src/Repository/CountryWorldPackCacheRepository.php` |
| Create | `src/Service/WorldPackCacheService.php` |
| Create | `src/Service/StarterPackService.php` |
| Create | `src/Command/WarmWorldPackCommand.php` |
| Create | `migrations/Version20260512000001.php` |
| Modify | `src/Entity/Club.php` — add `starterInitializedAt` field |
| Modify | `src/Service/WorldInitializationService.php` — promote snapshot methods to `public`, add `buildTierPack()`, remove `initialize()`, make `ABILITY_RANGES` public const |
| Modify | `src/Controller/InitializeController.php` — replace single action with `starter()`, `leagues()`, `tier()` |

---

## Task 1: Migration + Club.starterInitializedAt

**Files:**
- Create: `migrations/Version20260512000001.php`
- Modify: `src/Entity/Club.php`

- [ ] **Step 1: Create the migration**

Create `migrations/Version20260512000001.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add starter_initialized_at to club; create country_world_pack_cache table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD starter_initialized_at TIMESTAMPTZ NULL');

        $this->addSql('CREATE TABLE country_world_pack_cache (
            id UUID NOT NULL,
            country CHAR(2) NOT NULL,
            tier SMALLINT NOT NULL,
            payload JSONB NOT NULL,
            generated_at TIMESTAMPTZ NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT uq_country_tier UNIQUE (country, tier)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP COLUMN starter_initialized_at');
        $this->addSql('DROP TABLE country_world_pack_cache');
    }
}
```

- [ ] **Step 2: Add field and accessors to Club entity**

In `src/Entity/Club.php`, add after the `$worldInitializedAt` field (around line 59):

```php
    /** Set once when POST /api/initialize/starter succeeds. Guards re-initialization. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $starterInitializedAt = null;
```

In the accessors section, add after `isWorldInitialized()` (around line 181):

```php
    public function getStarterInitializedAt(): ?\DateTimeImmutable { return $this->starterInitializedAt; }
    public function setStarterInitializedAt(?\DateTimeImmutable $v): void { $this->starterInitializedAt = $v; }
    public function isStarterInitialized(): bool { return $this->starterInitializedAt !== null; }
```

- [ ] **Step 3: Run the migration**

```bash
lando php bin/console doctrine:migrations:migrate --no-interaction
```

Expected output includes: `++ migrating 20260512000001` and `Migration 20260512000001 was executed successfully`.

- [ ] **Step 4: Verify schema**

```bash
lando psql -c "\d club" | grep starter
lando psql -c "\d country_world_pack_cache"
```

Expected: `starter_initialized_at` column exists on `club`; `country_world_pack_cache` table exists with `id`, `country`, `tier`, `payload`, `generated_at` columns and a unique constraint on `(country, tier)`.

- [ ] **Step 5: Commit**

```bash
git checkout -b feat/initialize-chunked
git add migrations/Version20260512000001.php src/Entity/Club.php
git commit -m "feat: add starterInitializedAt to Club + country_world_pack_cache migration"
```

---

## Task 2: CountryWorldPackCache Entity + Repository

**Files:**
- Create: `src/Entity/CountryWorldPackCache.php`
- Create: `src/Repository/CountryWorldPackCacheRepository.php`

- [ ] **Step 1: Create the entity**

Create `src/Entity/CountryWorldPackCache.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CountryWorldPackCacheRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: CountryWorldPackCacheRepository::class)]
#[ORM\Table(name: 'country_world_pack_cache')]
#[ORM\UniqueConstraint(name: 'uq_country_tier', columns: ['country', 'tier'])]
class CountryWorldPackCache
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column]
    private \DateTimeImmutable $generatedAt;

    public function __construct(string $country, int $tier, array $payload)
    {
        $this->id          = new UuidV7();
        $this->country     = $country;
        $this->tier        = $tier;
        $this->payload     = $payload;
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getCountry(): string { return $this->country; }
    public function getTier(): int { return $this->tier; }
    public function getPayload(): array { return $this->payload; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
}
```

- [ ] **Step 2: Create the repository**

Create `src/Repository/CountryWorldPackCacheRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CountryWorldPackCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CountryWorldPackCache>
 */
class CountryWorldPackCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CountryWorldPackCache::class);
    }

    public function findForCountryAndTier(string $country, int $tier): ?CountryWorldPackCache
    {
        return $this->findOneBy(['country' => $country, 'tier' => $tier]);
    }

    /**
     * Returns the list of tier numbers already cached for a country.
     *
     * @return int[]
     */
    public function findCachedTiers(string $country): array
    {
        return array_column(
            $this->createQueryBuilder('c')
                ->select('c.tier')
                ->where('c.country = :country')
                ->setParameter('country', $country)
                ->getQuery()
                ->getArrayResult(),
            'tier'
        );
    }

    public function deleteByCountry(string $country): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.country = :country')
            ->setParameter('country', $country)
            ->getQuery()
            ->execute();
    }
}
```

- [ ] **Step 3: Verify Symfony can wire the service**

```bash
lando php bin/console debug:container CountryWorldPackCacheRepository
```

Expected: the service is listed without errors.

- [ ] **Step 4: Commit**

```bash
git add src/Entity/CountryWorldPackCache.php src/Repository/CountryWorldPackCacheRepository.php
git commit -m "feat: add CountryWorldPackCache entity and repository"
```

---

## Task 3: WorldPackCacheService

**Files:**
- Create: `src/Service/WorldPackCacheService.php`

- [ ] **Step 1: Create the service**

Create `src/Service/WorldPackCacheService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CountryWorldPackCache;
use App\Repository\CountryWorldPackCacheRepository;
use Doctrine\ORM\EntityManagerInterface;

class WorldPackCacheService
{
    public function __construct(
        private readonly CountryWorldPackCacheRepository $cacheRepository,
        private readonly EntityManagerInterface          $em,
    ) {}

    /**
     * Returns the cached payload for (country, tier).
     * If no cache entry exists, calls $generator(), stores the result, and returns it.
     *
     * @param callable(): array $generator
     */
    public function getOrBuild(string $country, int $tier, callable $generator): array
    {
        $cached = $this->cacheRepository->findForCountryAndTier($country, $tier);
        if ($cached !== null) {
            return $cached->getPayload();
        }

        $payload = $generator();
        $entry   = new CountryWorldPackCache($country, $tier, $payload);
        $this->em->persist($entry);
        $this->em->flush();

        return $payload;
    }

    /**
     * Returns true when every tier in $tierNumbers has a cache entry for $country.
     *
     * @param int[] $tierNumbers
     */
    public function allTiersCached(string $country, array $tierNumbers): bool
    {
        $cached = $this->cacheRepository->findCachedTiers($country);
        return count(array_diff($tierNumbers, $cached)) === 0;
    }

    /**
     * Deletes all cached tiers for $country. Used by WarmWorldPackCommand --force.
     */
    public function deleteByCountry(string $country): int
    {
        return $this->cacheRepository->deleteByCountry($country);
    }
}
```

- [ ] **Step 2: Verify wiring**

```bash
lando php bin/console debug:container WorldPackCacheService
```

Expected: service listed without errors.

- [ ] **Step 3: Commit**

```bash
git add src/Service/WorldPackCacheService.php
git commit -m "feat: add WorldPackCacheService (getOrBuild, allTiersCached, deleteByCountry)"
```

---

## Task 4: Promote Methods in WorldInitializationService + Add buildTierPack()

**Files:**
- Modify: `src/Service/WorldInitializationService.php`

The snapshot methods and `distributeByPosition()` are currently `private`. `StarterPackService` (Task 5) needs to call them. `ABILITY_RANGES` is also needed externally. This task makes those changes and adds `buildTierPack()`.

- [ ] **Step 1: Make ABILITY_RANGES a public const**

In `src/Service/WorldInitializationService.php`, change line 32:

```php
// Before
private const ABILITY_RANGES = [

// After
public const ABILITY_RANGES = [
```

- [ ] **Step 2: Promote snapshot methods and distributeByPosition to public**

Change the visibility of these four methods from `private` to `public`:
- `distributeByPosition(int $total, PoolConfig $config): array`
- `buildPlayerSnapshot(Player $player): array`
- `buildStaffSnapshot(Staff $staff): array`
- `buildScoutSnapshot(Scout $scout): array`

(The `buildLeagueSnapshot`, `buildClubSnapshot`, `rollLeagueSponsors`, `fillStaffRole`, `defaultTierConfig` methods remain as-is.)

- [ ] **Step 3: Add buildTierPack() method**

Add this method after `buildLeaguesPack()` (around line 161), before `initialize()`:

```php
    /**
     * Builds the NPC club + player + fixture pack for a single league tier in a country.
     * Consumes (deletes) pool players and staff used for NPC snapshots.
     *
     * @return array{id: string, tier: int, name: string, clubs: array, fixtures: array, ...}
     */
    public function buildTierPack(Club $club, string $country, int $tier): array
    {
        $starterConfig = $this->starterConfigRepository->getConfig();
        $npcConfig     = $starterConfig->getNpcSquadConfig();
        $leagueRanges  = $starterConfig->getLeagueAbilityRanges();
        $poolConfig    = $this->poolConfigRepository->getConfig();
        $gameConfig    = $this->gameConfigRepository->getConfig();

        $league = $this->leagueRepository->findByCountryAndTier($country, $tier);
        if ($league === null) {
            throw new \InvalidArgumentException("No league found for country={$country} tier={$tier}");
        }

        $nationality  = ClubInitializationService::countryToNationality($country) ?? $country;
        $tierKey      = (string) $tier;
        $tierConf     = $npcConfig[$tierKey] ?? $this->defaultTierConfig($tier);
        $configured   = $leagueRanges[$country][$tierKey] ?? null;
        $abilityRange = ($configured && ($configured['min'] ?? 0) > 0)
            ? ['min' => (int) $configured['min'], 'max' => (int) $configured['max']]
            : (self::ABILITY_RANGES[$tier] ?? ['min' => 5, 'max' => 35]);

        $npcClubs     = $this->npcClubRepository->findByLeague($league);
        $clubsData    = [];
        $allClubIds   = [];
        $npcPlayerIds = [];
        $npcStaffIds  = [];

        if ($club->getCurrentLeague()?->getId()->toBinary() === $league->getId()->toBinary()) {
            $allClubIds[] = (string) $club->getId();
        }

        foreach ($npcClubs as $npcClub) {
            $allClubIds[] = (string) $npcClub->getId();
            $totalPlayers = random_int((int) $tierConf['playerMin'], (int) $tierConf['playerMax']);
            $foreignPct   = (int) $tierConf['foreignPercent'];
            $posCounts    = $this->distributeByPosition($totalPlayers, $poolConfig);
            $players      = [];

            foreach ($posCounts as $posValue => $posTotal) {
                $position      = PlayerPosition::from($posValue);
                $foreignCount  = (int) round($posTotal * $foreignPct / 100);
                $domesticCount = $posTotal - $foreignCount;

                $domestic = $this->playerRepository->findForWorldInitByPositionAndNationality(
                    $abilityRange['min'], $abilityRange['max'], $position, $nationality, $domesticCount
                );
                if (count($domestic) < $domesticCount) {
                    $deficit  = $domesticCount - count($domestic);
                    $extra    = $this->playerRepository->findForeignForWorldInitByPosition(
                        $abilityRange['min'], $abilityRange['max'], '__none__', $position, $deficit
                    );
                    $domestic = array_merge($domestic, $extra);
                }

                $foreign = $this->playerRepository->findForeignForWorldInitByPosition(
                    $abilityRange['min'], $abilityRange['max'], $nationality, $position, $foreignCount
                );
                if (count($foreign) < $foreignCount) {
                    $deficit = $foreignCount - count($foreign);
                    $extra   = $this->playerRepository->findForWorldInitByPositionAndNationality(
                        $abilityRange['min'], $abilityRange['max'], $position, $nationality, $deficit
                    );
                    $foreign = array_merge($foreign, $extra);
                }

                $players = array_merge($players, $domestic, $foreign);
            }

            $players  = array_values(array_unique($players, SORT_REGULAR));
            $managers = $this->staffRepository->findInPoolByRoleRandom(StaffRole::MANAGER,  (int) $tierConf['managerCount']);
            $coaches  = $this->staffRepository->findInPoolByRoleRandom(StaffRole::COACH,    (int) $tierConf['coachCount']);
            $chairmen = $this->staffRepository->findInPoolByRoleRandom(StaffRole::CHAIRMAN, (int) $tierConf['chairmanCount']);
            $staff    = array_merge($managers, $coaches, $chairmen);

            foreach ($players as $p) { $npcPlayerIds[] = (string) $p->getId(); }
            foreach ($staff   as $s) { $npcStaffIds[]  = (string) $s->getId(); }

            $clubsData[] = $this->buildClubSnapshot($npcClub, $players, $staff);
        }

        $fixtures   = $this->fixtureGenerationService->generate($allClubIds);
        $sponsorPot = $this->rollLeagueSponsors($league, $gameConfig);

        $this->playerRepository->deleteByIds($npcPlayerIds);
        $this->staffRepository->deleteByIds($npcStaffIds);

        return $this->buildLeagueSnapshot($league, $clubsData, $fixtures, $sponsorPot, $gameConfig);
    }
```

- [ ] **Step 4: Remove initialize() from WorldInitializationService**

Delete the entire `initialize(Club $club): array` method (lines 163–232 in the original file). It is fully replaced by `StarterPackService::initialize()` (Task 5) and the new controller actions (Task 6).

- [ ] **Step 5: Verify container still compiles**

```bash
lando php bin/console cache:clear
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Service/WorldInitializationService.php
git commit -m "refactor: promote snapshot methods to public, add buildTierPack(), remove initialize()"
```

---

## Task 5: StarterPackService

**Files:**
- Create: `src/Service/StarterPackService.php`

- [ ] **Step 1: Create the service**

Create `src/Service/StarterPackService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
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

- [ ] **Step 2: Verify wiring**

```bash
lando php bin/console debug:container StarterPackService
```

Expected: service listed without errors.

- [ ] **Step 3: Commit**

```bash
git add src/Service/StarterPackService.php
git commit -m "feat: add StarterPackService (AMP squad assembly extracted from WorldInitializationService)"
```

---

## Task 6: Refactor InitializeController

**Files:**
- Modify: `src/Controller/InitializeController.php`

Replace the file entirely with three focused action methods.

- [ ] **Step 1: Rewrite InitializeController**

Replace the full contents of `src/Controller/InitializeController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ClubRepository;
use App\Repository\LeagueRepository;
use App\Repository\PlayerRepository;
use App\Service\ClubInitializationService;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/initialize')]
class InitializeController extends AbstractController
{
    private const MIN_POOL_SIZE = 500;

    public function __construct(
        private readonly ClubRepository             $clubRepository,
        private readonly PlayerRepository           $playerRepository,
        private readonly LeagueRepository           $leagueRepository,
        private readonly StarterPackService         $starterPackService,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly WorldPackCacheService      $worldPackCacheService,
        private readonly EntityManagerInterface     $em,
    ) {}

    /**
     * POST /api/initialize/starter
     *
     * Step 1: Assigns AMP squad (players, staff, scouts) to the club and returns the starter pack.
     * Idempotent — returns starter data again if already initialized.
     */
    #[Route('/starter', name: 'api_initialize_starter', methods: ['POST'])]
    public function starter(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Accept optional ?country= override
        $countryParam = $request->query->get('country');
        if ($countryParam !== null) {
            $countryParam = strtoupper(trim($countryParam));
            if (ClubInitializationService::countryToNationality($countryParam) === null) {
                return $this->json(
                    ['error' => "Unknown country code '{$countryParam}'."],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
            $club->setCountry($countryParam);
            $this->em->flush();
        }

        if ($club->getCountry() === null && $club->getCurrentLeague() !== null) {
            $club->setCountry($club->getCurrentLeague()->getCountry());
            $this->em->flush();
        }

        if ($club->getCountry() === null) {
            return $this->json(
                ['error' => 'Club must have a country set before initialization. Pass ?country=<code>.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $poolCount = $this->playerRepository->countInPool();
        if ($poolCount < self::MIN_POOL_SIZE) {
            return $this->json(
                ['error' => "Player pool too small ({$poolCount} players). Run GenerateMarketDataCommand first."],
                Response::HTTP_PRECONDITION_FAILED
            );
        }

        if ($club->isStarterInitialized()) {
            return $this->json(
                ['error' => 'Starter already initialized.'],
                Response::HTTP_CONFLICT
            );
        }

        $ampStarter = $this->starterPackService->initialize($club);

        return $this->json(['ampStarter' => $ampStarter]);
    }

    /**
     * GET /api/initialize/leagues
     *
     * Step 2: Returns lightweight league metadata for the club's country (no NPC squads).
     * Requires starter to be initialized first.
     */
    #[Route('/leagues', name: 'api_initialize_leagues', methods: ['GET'])]
    public function leagues(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$club->isStarterInitialized()) {
            return $this->json(
                ['error' => 'Call POST /api/initialize/starter before fetching leagues.'],
                Response::HTTP_PRECONDITION_FAILED
            );
        }

        $leagues = $this->leagueRepository->findByCountry($club->getCountry());

        $data = array_map(fn($league) => [
            'id'               => (string) $league->getId(),
            'tier'             => $league->getTier(),
            'name'             => $league->getName(),
            'country'          => $league->getCountry(),
            'promotionSpots'   => $league->getPromotionSpots(),
            'reputationTier'   => $league->getLeagueReputationTier()?->value,
            'tvDeal'           => $league->getTvDeal(),
            'prizeMoney'       => $league->getPrizeMoney(),
            'leaguePositionPot' => $league->getLeaguePositionPot(),
        ], $leagues);

        return $this->json(['leagues' => $data]);
    }

    /**
     * POST /api/initialize/league/{tier}
     *
     * Step 3: Returns NPC clubs + squads + fixtures for one league tier.
     * Generates and caches on first call; subsequent calls (including retries) return cached data.
     * Sets worldInitializedAt once all tiers for the country are cached.
     */
    #[Route('/league/{tier}', name: 'api_initialize_league_tier', requirements: ['tier' => '\d+'], methods: ['POST'])]
    public function tier(int $tier): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$club->isStarterInitialized()) {
            return $this->json(
                ['error' => 'Call POST /api/initialize/starter before fetching league tiers.'],
                Response::HTTP_PRECONDITION_FAILED
            );
        }

        $country = $club->getCountry();
        $league  = $this->leagueRepository->findByCountryAndTier($country, $tier);

        if ($league === null) {
            return $this->json(
                ['error' => "No league found for country={$country} tier={$tier}."],
                Response::HTTP_NOT_FOUND
            );
        }

        $payload = $this->worldPackCacheService->getOrBuild(
            $country,
            $tier,
            fn() => $this->worldInitializationService->buildTierPack($club, $country, $tier)
        );

        // Check if all tiers are now cached → mark world as fully initialized
        if (!$club->isWorldInitialized()) {
            $allTiers = array_map(
                fn($l) => $l->getTier(),
                $this->leagueRepository->findByCountry($country)
            );
            if ($this->worldPackCacheService->allTiersCached($country, $allTiers)) {
                $club->setWorldInitializedAt(new \DateTimeImmutable());
                $this->em->flush();
            }
        }

        return $this->json(['tier' => $tier, 'data' => $payload]);
    }
}
```

- [ ] **Step 2: Verify routes are registered**

```bash
lando php bin/console debug:router | grep initialize
```

Expected output includes three routes:
```
api_initialize_starter    POST   /api/initialize/starter
api_initialize_leagues    GET    /api/initialize/leagues
api_initialize_league_tier POST  /api/initialize/league/{tier}
```

- [ ] **Step 3: Clear cache and verify container**

```bash
lando php bin/console cache:clear
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/InitializeController.php
git commit -m "feat: split InitializeController into starter/leagues/tier endpoints"
```

---

## Task 7: WarmWorldPackCommand

**Files:**
- Create: `src/Command/WarmWorldPackCommand.php`

- [ ] **Step 1: Create the command**

Create `src/Command/WarmWorldPackCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\User;
use App\Repository\CountryWorldPackCacheRepository;
use App\Repository\LeagueRepository;
use App\Service\ClubInitializationService;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:worldpack:warm',
    description: 'Pre-generate and cache the NPC league pack for a country.',
)]
class WarmWorldPackCommand extends Command
{
    public function __construct(
        private readonly LeagueRepository                $leagueRepository,
        private readonly CountryWorldPackCacheRepository $cacheRepository,
        private readonly WorldInitializationService      $worldInitializationService,
        private readonly WorldPackCacheService           $worldPackCacheService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. EN, ES)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete existing cache entries before regenerating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $country = strtoupper(trim((string) $input->getArgument('country')));

        if (ClubInitializationService::countryToNationality($country) === null) {
            $io->error("Unknown country code '{$country}'. Supported codes: EN, IT, DE, ES, BR, AR, NL, FR, PT, NG, GH, JP, KR, SE, DK, IE, CI, SN, CN");
            return Command::FAILURE;
        }

        $leagues = $this->leagueRepository->findByCountry($country);
        if (empty($leagues)) {
            $io->error("No leagues found for country '{$country}'. Seed leagues first.");
            return Command::FAILURE;
        }

        if ($input->getOption('force')) {
            $deleted = $this->worldPackCacheService->deleteByCountry($country);
            $io->note("--force: deleted {$deleted} existing cache entries for {$country}.");
        }

        // buildTierPack() uses $club->getCurrentLeague() only to include the player's
        // club in fixture generation. For pre-warming, no player club exists, so we
        // pass a transient stub with no league — it is never persisted.
        $dummyClub = new Club('__warmup__', new User());
        $dummyClub->setCountry($country);

        foreach ($leagues as $league) {
            $tier        = $league->getTier();
            $alreadyHit  = $this->cacheRepository->findForCountryAndTier($country, $tier) !== null;

            $io->write("[tier {$tier}] ");

            $payload     = $this->worldPackCacheService->getOrBuild(
                $country,
                $tier,
                fn() => $this->worldInitializationService->buildTierPack($dummyClub, $country, $tier)
            );

            if ($alreadyHit) {
                $io->writeln('already cached — skipped');
            } else {
                $clubCount   = count($payload['clubs'] ?? []);
                $playerCount = array_sum(array_map(fn($c) => count($c['players'] ?? []), $payload['clubs'] ?? []));
                $io->writeln("generated ({$clubCount} clubs, {$playerCount} players)");
            }
        }

        $io->success("World pack warmed for {$country}.");
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Add findDummyClubForCountry — not needed**

The transient stub approach (creating a non-persisted `Club` object) means no additional repository method is required. Skip this step.

- [ ] **Step 4: Verify command is registered**

```bash
lando php bin/console list app:worldpack
```

Expected: `app:worldpack:warm` listed.

- [ ] **Step 5: Dry-run the command against a seeded country**

```bash
lando php bin/console app:worldpack:warm EN
```

Expected: progress lines per tier, e.g.:
```
[tier 1] generated (20 clubs, 400 players)
[tier 2] generated (20 clubs, 360 players)
...
[OK] World pack warmed for EN.
```

Running it a second time:
```bash
lando php bin/console app:worldpack:warm EN
```

Expected: all tiers show `already cached — skipped`.

Running with `--force`:
```bash
lando php bin/console app:worldpack:warm EN --force
```

Expected: note about deleted entries, then all tiers regenerated.

- [ ] **Step 6: Commit**

```bash
git add src/Command/WarmWorldPackCommand.php src/Repository/ClubRepository.php
git commit -m "feat: add WarmWorldPackCommand (app:worldpack:warm {country} [--force])"
```

---

## Task 8: End-to-End Verification

- [ ] **Step 1: Register a test user and get a JWT**

```bash
curl -s -X POST http://wunderkind.lndo.site/api/register \
  -H "Content-Type: application/json" \
  -d '{"email":"init-test@test.com","password":"password123","clubName":"Test FC"}' | jq .

TOKEN=$(curl -s -X POST http://wunderkind.lndo.site/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"init-test@test.com","password":"password123"}' | jq -r .token)

echo "Token: $TOKEN"
```

- [ ] **Step 2: Call starter endpoint**

```bash
curl -s -X POST "http://wunderkind.lndo.site/api/initialize/starter?country=EN" \
  -H "Authorization: Bearer $TOKEN" | jq '{players: (.ampStarter.players | length), staff: (.ampStarter.staff | length), scouts: (.ampStarter.scouts | length)}'
```

Expected: `{ "players": N, "staff": N, "scouts": N }` with non-zero values.

- [ ] **Step 3: Call leagues endpoint**

```bash
curl -s "http://wunderkind.lndo.site/api/initialize/leagues" \
  -H "Authorization: Bearer $TOKEN" | jq '.leagues | length'
```

Expected: number of leagues for England (e.g. 8).

- [ ] **Step 4: Call tier endpoint for tier 1**

```bash
curl -s -X POST "http://wunderkind.lndo.site/api/initialize/league/1" \
  -H "Authorization: Bearer $TOKEN" | jq '{tier: .tier, clubs: (.data.clubs | length)}'
```

Expected: `{ "tier": 1, "clubs": N }`.

- [ ] **Step 5: Verify cache row written**

```bash
lando psql -c "SELECT country, tier, generated_at FROM country_world_pack_cache ORDER BY tier;"
```

Expected: row for `(EN, 1)` present.

- [ ] **Step 6: Verify retry is served from cache (no pool consumption)**

```bash
curl -s -X POST "http://wunderkind.lndo.site/api/initialize/league/1" \
  -H "Authorization: Bearer $TOKEN" | jq '.tier'
```

Expected: `1` — same response, no error.

- [ ] **Step 7: Verify starter idempotency returns 409**

```bash
curl -s -o /dev/null -w "%{http_code}" -X POST "http://wunderkind.lndo.site/api/initialize/starter?country=EN" \
  -H "Authorization: Bearer $TOKEN"
```

Expected: `409`.

- [ ] **Step 8: Final commit and push**

```bash
git push -u origin feat/initialize-chunked
```

Then open a PR:
```bash
gh pr create --title "feat: split /initialize into chunked endpoints with country worldpack cache" \
  --body "Replaces monolithic POST /api/initialize with POST /starter, GET /leagues, POST /league/{tier}. Adds CountryWorldPackCache for resume support and app:worldpack:warm CLI command."
```
