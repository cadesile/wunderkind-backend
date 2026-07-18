# Account Deletion Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `POST /api/account/delete` — an authenticated user permanently deletes their own account and every club they own, with all club-dependent data.

**Architecture:** A thin `AccountController` resolves the caller from the JWT (`getUser()`) and delegates to `AccountDeletionService`, which tears down each club's non-cascaded FK dependents (DQL) then `em->remove($user)` (ORM/DB cascades handle the rest) inside one transaction.

**Tech Stack:** Symfony 8 / PHP 8.4, Doctrine ORM 3, PostgreSQL 16, PHPUnit. All commands run inside Lando (`lando php ...`).

## Global Constraints

- All PHP/console/phpunit commands run inside Lando: `lando php vendor/bin/phpunit ... --no-coverage`.
- Never commit to `master`. Create branch `feat/account-delete-endpoint` before Task 1.
- **JWT alone** authorizes deletion — no password re-confirmation, no request body.
- Responses: success → `200` `{"success": true}`; teardown failure → `500` `{"success": false}`; missing/invalid token → `401` (firewall).
- **No schema changes / no migration** — deletion only.
- Functional tests use the separate `wunderkind_test` DB (env `test`); it already has all these tables, so no reconcile is expected.
- The `api` firewall is named `api`, stateless, provider `app_user_provider`. Test auth: `$client->loginUser($user, 'api')` with a **persisted** user carrying `ROLE_CLUB` (the provider re-fetches by email).
- The five FK-blocking, non-cascaded club dependents (verified against `information_schema`) are exactly: `SeasonSnapshot`, `SeasonRecord`, `MatchResult`, `Investor`, `Sponsor`. Everything else (`SyncRecord`/`LeaderboardEntry`/`InboxMessage` ORM cascade, `transfer` SET NULL, `club` ORM cascade from `User.clubs`, `email_verification` DB cascade) is handled by `em->remove($user)`.

---

### Task 1: AccountDeletionService

**Files:**
- Create: `src/Service/AccountDeletionService.php`
- Test: `tests/Service/AccountDeletionServiceTest.php`

**Interfaces:**
- Produces: `AccountDeletionService::deleteAccount(User $user): void` — permanently deletes the user, their clubs, and all club-dependent rows, in one transaction.

- [ ] **Step 1: Write the failing test**

`tests/Service/AccountDeletionServiceTest.php`:
```php
<?php

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\League;
use App\Entity\MatchResult;
use App\Entity\SeasonRatingsSnapshot;
use App\Entity\SeasonRecord;
use App\Entity\SeasonSnapshot;
use App\Entity\Sponsor;
use App\Entity\SyncRecord;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\TransferType;
use App\Service\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AccountDeletionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDeletesUserClubsAndAllDependents(): void
    {
        $user = new User('delete-me-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);

        $club = new Club('Doomed FC', $user);
        $this->em->persist($club);

        $league = new League('zz', 8, 'Del Test League');
        $this->em->persist($league);

        $investor = new Investor('Inv Co');
        $investor->setClub($club);
        $this->em->persist($investor);

        $sponsor = new Sponsor('Spn Co');
        $sponsor->setClub($club);
        $this->em->persist($sponsor);

        $this->em->persist(new MatchResult($club, 2, 1, 3, 1));
        $this->em->persist(new SeasonRecord($club, $league, 1, 4, 10, 5, 3, 2, 12, 8, 18, false, false));
        $this->em->persist(new SeasonSnapshot($club, 1, 'zz', ['x' => 1]));
        $this->em->persist(new SeasonRatingsSnapshot(1, 1, 8, (string) $club->getId(), 'Doomed FC', 50, 4));
        $this->em->persist(new SyncRecord($club, 5, new \DateTimeImmutable(), ['w' => 5]));

        $transfer = new Transfer(null, $club, 'Some Club', TransferType::SALE, new \DateTimeImmutable());
        $this->em->persist($transfer);

        $this->em->flush();

        $userId     = $user->getId();
        $clubId     = $club->getId();
        $transferId = $transfer->getId();

        self::getContainer()->get(AccountDeletionService::class)->deleteAccount($user);
        $this->em->clear();

        // Account + club gone.
        $this->assertNull($this->em->find(User::class, $userId), 'user should be deleted');
        $this->assertNull($this->em->find(Club::class, $clubId), 'club should be deleted');

        // Every FK-blocking dependent gone (count rows still pointing at the club id).
        foreach (['Investor', 'Sponsor', 'MatchResult', 'SeasonRecord', 'SeasonSnapshot', 'SyncRecord'] as $entity) {
            $count = (int) $this->em->createQuery(
                "SELECT COUNT(e) FROM App\\Entity\\{$entity} e WHERE IDENTITY(e.club) = :cid"
            )->setParameter('cid', $clubId)->getSingleScalarResult();
            $this->assertSame(0, $count, "$entity rows should be gone");
        }

        // Denormalized ratings snapshot (string club id, no FK) gone.
        $ratings = (int) $this->em->createQuery(
            'SELECT COUNT(e) FROM App\Entity\SeasonRatingsSnapshot e WHERE e.clubId = :id'
        )->setParameter('id', (string) $clubId)->getSingleScalarResult();
        $this->assertSame(0, $ratings, 'season ratings snapshot rows should be gone');

        // Transfer history is retained with a nulled club (ON DELETE SET NULL).
        $transfer = $this->em->find(Transfer::class, $transferId);
        $this->assertNotNull($transfer, 'transfer history should survive');
        $this->assertNull($transfer->getClub(), 'transfer club should be nulled');
    }

    public function testDeletesAllClubsForMultiClubUser(): void
    {
        $user = new User('multi-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);

        $clubA = new Club('Club A', $user);
        $clubB = new Club('Club B', $user);
        $this->em->persist($clubA);
        $this->em->persist($clubB);
        $invA = new Investor('A'); $invA->setClub($clubA); $this->em->persist($invA);
        $invB = new Investor('B'); $invB->setClub($clubB); $this->em->persist($invB);
        $this->em->flush();

        $idA = $clubA->getId();
        $idB = $clubB->getId();

        self::getContainer()->get(AccountDeletionService::class)->deleteAccount($user);
        $this->em->clear();

        $this->assertNull($this->em->find(Club::class, $idA));
        $this->assertNull($this->em->find(Club::class, $idB));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Service/AccountDeletionServiceTest.php --no-coverage`
Expected: FAIL — `Service "App\Service\AccountDeletionService" not found` (or class not found).

- [ ] **Step 3: Create the service**

`src/Service/AccountDeletionService.php`:
```php
<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Permanently deletes a user account and every club they own, including all
 * club-dependent data that ORM/DB cascades don't cover. Runs in one transaction.
 */
final class AccountDeletionService
{
    /**
     * Club dependents with an ON-DELETE NO-ACTION FK that Doctrine does NOT
     * cascade-remove — they must be deleted before their club or the FK blocks.
     * (SyncRecord/LeaderboardEntry/InboxMessage ARE cascade:remove; transfer is
     * SET NULL; email_verification is DB cascade — all handled by remove($user).)
     */
    private const BLOCKING_CLUB_DEPENDENTS = [
        'SeasonSnapshot',
        'SeasonRecord',
        'MatchResult',
        'Investor',
        'Sponsor',
    ];

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function deleteAccount(User $user): void
    {
        $this->em->wrapInTransaction(function () use ($user): void {
            foreach ($user->getClubs() as $club) {
                foreach (self::BLOCKING_CLUB_DEPENDENTS as $entity) {
                    $this->em->createQuery("DELETE FROM App\\Entity\\{$entity} e WHERE e.club = :club")
                        ->setParameter('club', $club)
                        ->execute();
                }

                // Denormalized snapshot keyed by club id string (no FK, still the user's data).
                $this->em->createQuery('DELETE FROM App\Entity\SeasonRatingsSnapshot e WHERE e.clubId = :id')
                    ->setParameter('id', (string) $club->getId())
                    ->execute();
            }

            // Cascades: User.clubs (remove) → SyncRecord/LeaderboardEntry/InboxMessage;
            // transfer.club_id/player_id → SET NULL; email_verification → DB cascade.
            $this->em->remove($user);
            $this->em->flush();
        });
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Service/AccountDeletionServiceTest.php --no-coverage`
Expected: PASS (2 tests). If a `column ... does not exist` error appears, the test DB drifted — reconcile per CLAUDE.md Testing, then re-run.

- [ ] **Step 5: Commit**

```bash
git add src/Service/AccountDeletionService.php tests/Service/AccountDeletionServiceTest.php
git commit -m "feat: AccountDeletionService — delete user, clubs, and all club dependents"
```

---

### Task 2: AccountController endpoint

**Files:**
- Create: `src/Controller/Api/AccountController.php`
- Test: `tests/Controller/Api/AccountControllerTest.php`

**Interfaces:**
- Consumes: `AccountDeletionService::deleteAccount(User $user): void` (Task 1).
- Produces: `POST /api/account/delete` (`api_account_delete`), `ROLE_CLUB`, returns `{"success": bool}`.

- [ ] **Step 1: Write the failing test**

`tests/Controller/Api/AccountControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AccountControllerTest extends WebTestCase
{
    public function testAuthenticatedDeleteRemovesAccountAndReturnsSuccess(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('acct-del-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->persist(new Club('Endpoint FC', $user));
        $em->flush();
        $userId = $user->getId();

        $client->loginUser($user, 'api');
        $client->request('POST', '/api/account/delete');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        $em->clear();
        $this->assertNull($em->find(User::class, $userId), 'user should be deleted');
    }

    public function testUnauthenticatedDeleteIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/account/delete');
        $this->assertResponseStatusCodeSame(401);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Controller/Api/AccountControllerTest.php --no-coverage`
Expected: FAIL — the authenticated call 404s (route missing), so `assertResponseStatusCodeSame(200)` fails.

- [ ] **Step 3: Create the controller**

`src/Controller/Api/AccountController.php`:
```php
<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\AccountDeletionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/account')]
class AccountController extends AbstractController
{
    #[Route('/delete', name: 'api_account_delete', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function delete(AccountDeletionService $accountDeletionService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $accountDeletionService->deleteAccount($user);
        } catch (\Throwable) {
            return $this->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['success' => true]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Controller/Api/AccountControllerTest.php --no-coverage`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Api/AccountController.php tests/Controller/Api/AccountControllerTest.php
git commit -m "feat: POST /api/account/delete endpoint"
```

---

### Task 3: Docs + full-suite check

**Files:**
- Modify: `CLAUDE.md` (API Endpoints table)

- [ ] **Step 1: Add the endpoint to the API table**

In `CLAUDE.md`, add a row under the API Endpoints table (near the other `/api/*` JWT rows):
```
| `POST` | `/api/account/delete` | JWT | Permanently delete the caller's account + all owned clubs and their data |
```

- [ ] **Step 2: Run the full suite**

Run: `lando php vendor/bin/phpunit --no-coverage`
Expected: the 4 new tests pass; no new failures (the flaky `testAgeIsWithinRange` is pre-existing/unrelated — ignore only that one).

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document POST /api/account/delete"
```

---

## Self-Review Notes

- **Spec coverage:** endpoint + auth (Task 2), service teardown with verified FK list (Task 1), transaction/rollback (`wrapInTransaction`, Task 1), transfer-retention + multi-club + all-dependents assertions (Task 1 tests), 401 for unauth (Task 2 test), docs (Task 3). JWT-alone and no-schema-change honored (Global Constraints).
- **Type consistency:** `deleteAccount(User): void` used identically in Task 1 (definition) and Task 2 (call). `BLOCKING_CLUB_DEPENDENTS` list matches the spec's verified five.
- **Known soft spot:** the two functional tests hit `wunderkind_test`; if it has schema drift unrelated to this feature, reconcile per CLAUDE.md before judging. `loginUser($user, 'api')` on the stateless firewall is the documented Symfony test approach; if it fails to authenticate, verify the firewall name in `config/packages/security.yaml` is `api`.
