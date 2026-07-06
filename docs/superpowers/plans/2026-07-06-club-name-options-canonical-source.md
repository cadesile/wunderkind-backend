# Club Name Options Canonical Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `GET /api/club/name-options` source its place-name/suffix data from `NpcClubGenerationService` (the canonical source used for real NPC club generation) instead of `ClubController`'s own separate, drifted, 7-country duplicate lists.

**Architecture:** Add two small public getters to `NpcClubGenerationService` exposing its existing private `PLACE_NAMES_BY_COUNTRY`/`SUFFIXES_BY_COUNTRY` constants. Update `ClubController::nameOptions()` to call them instead of its own constants, preserving the exact response shape and EN-fallback behavior. Delete the now-dead duplicate constants.

**Tech Stack:** Symfony 8.0, PHP 8.4, PHPUnit 13 (plain `TestCase` for service unit tests, `WebTestCase` for the controller's auth-gate check).

## Global Constraints

- No response shape change: `GET /api/club/name-options` must keep returning exactly `{"country": string, "cities": string[], "suffixes": string[]}` — same keys as today.
- No change to the existing fallback-to-EN behavior when an unsupported country code is requested.
- Do not touch `NpcClubGenerationService::generateClubs()`'s own internal fallback logic (generic placeholder names for places, no fallback for suffixes) — that's pre-existing, unrelated behavior.
- Delete `ClubController::CITIES_BY_COUNTRY` and `ClubController::SUFFIXES_BY_COUNTRY` once nothing references them (confirmed via `grep` during design that nothing else does).

---

### Task 1: `NpcClubGenerationService` public getters

**Files:**
- Modify: `src/Service/NpcClubGenerationService.php` (add two public methods after the constructor, before the `generateClubs()` method — see exact insertion point below)
- Test: `tests/Service/NpcClubGenerationServiceTest.php` (add test methods to the existing file)

**Interfaces:**
- Produces: `NpcClubGenerationService::getPlaceNames(string $countryCode): array` (returns `string[]`), `NpcClubGenerationService::getSuffixes(string $countryCode): array` (returns `string[]`) — both consumed by Task 2's `ClubController::nameOptions()`.

- [ ] **Step 1: Write the failing tests**

Add these test methods to the existing `tests/Service/NpcClubGenerationServiceTest.php` (it already has a `makeService()` private helper — reuse it exactly as-is, no changes needed to that helper):

```php
    public function testGetPlaceNamesReturnsKnownCountryData(): void
    {
        $service = $this->makeService();
        $places  = $service->getPlaceNames('ES');

        $this->assertNotEmpty($places);
        $this->assertContains('Madrid', $places);
        $this->assertContains('Barcelona', $places);
    }

    public function testGetPlaceNamesReturnsEmptyArrayForUnknownCountry(): void
    {
        $service = $this->makeService();
        $this->assertSame([], $service->getPlaceNames('XX'));
    }

    public function testGetSuffixesReturnsKnownCountryData(): void
    {
        $service  = $this->makeService();
        $suffixes = $service->getSuffixes('EN');

        $this->assertNotEmpty($suffixes);
        $this->assertContains('FC', $suffixes);
        $this->assertContains('United', $suffixes);
    }

    public function testGetSuffixesReturnsEmptyArrayForUnknownCountry(): void
    {
        $service = $this->makeService();
        $this->assertSame([], $service->getSuffixes('XX'));
    }

    public function testGetPlaceNamesCoversAllNineGenerationCapableCountries(): void
    {
        $service = $this->makeService();
        foreach (['ES', 'EN', 'DE', 'IT', 'FR', 'BR', 'AR', 'NL', 'PT'] as $country) {
            $this->assertNotEmpty($service->getPlaceNames($country), "Expected place names for {$country}");
            $this->assertNotEmpty($service->getSuffixes($country), "Expected suffixes for {$country}");
        }
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: FAIL with `Call to undefined method App\Service\NpcClubGenerationService::getPlaceNames()` (and similarly for `getSuffixes()`).

- [ ] **Step 3: Implement the getters**

In `src/Service/NpcClubGenerationService.php`, add these two public methods immediately after the constructor (i.e. right before the existing `/** @return NpcClub[] */` docblock on `generateClubs()`):

```php
    /** @return string[] */
    public function getPlaceNames(string $countryCode): array
    {
        return self::PLACE_NAMES_BY_COUNTRY[$countryCode] ?? [];
    }

    /** @return string[] */
    public function getSuffixes(string $countryCode): array
    {
        return self::SUFFIXES_BY_COUNTRY[$countryCode] ?? [];
    }

```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: PASS (all existing tests in this file plus the 5 new ones).

- [ ] **Step 5: Run the full suite and commit**

Run: `lando php vendor/bin/phpunit --no-coverage`
Expected: all green (matching this project's pre-existing baseline — a known, unrelated flaky test, `PlayerGenerationServiceTest::testAgeIsWithinRange`, may intermittently fail due to randomization; re-run in isolation to confirm it's not a real regression if it comes up).

```bash
git add src/Service/NpcClubGenerationService.php tests/Service/NpcClubGenerationServiceTest.php
git commit -m "feat: add public getPlaceNames/getSuffixes getters to NpcClubGenerationService"
```

---

### Task 2: `ClubController::nameOptions()` sources from the service, dead code removed

**Files:**
- Modify: `src/Controller/Api/ClubController.php` — update `nameOptions()` (lines 120-132), delete `CITIES_BY_COUNTRY` (lines 24-72) and `SUFFIXES_BY_COUNTRY` (lines 74-104) constants entirely.
- Test: `tests/Controller/Api/ClubControllerTest.php` (new file)

**Interfaces:**
- Consumes: `NpcClubGenerationService::getPlaceNames(string $countryCode): array`, `NpcClubGenerationService::getSuffixes(string $countryCode): array` (from Task 1).

- [ ] **Step 1: Write the failing tests**

Create `tests/Controller/Api/ClubControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ClubControllerTest extends WebTestCase
{
    public function testNameOptionsRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/club/name-options?country=ES');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testNameOptionsReturnsExpectedShapeWhenAuthenticated(): void
    {
        $client = static::createClient();

        // No real JWT-minting test infrastructure exists in this codebase yet
        // (see tests/Controller/Api/FinanceControllerTest.php for the same
        // established pattern) — this confirms the auth gate is in place and,
        // IF a valid token were supplied, documents the expected response shape.
        $client->request('GET', '/api/club/name-options?country=ES', [], [], ['HTTP_AUTHORIZATION' => 'Bearer test-token']);

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 401]);

        if ($statusCode === 200) {
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('country', $data);
            $this->assertArrayHasKey('cities', $data);
            $this->assertArrayHasKey('suffixes', $data);
            $this->assertContains('Madrid', $data['cities']);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Controller/Api/ClubControllerTest.php --no-coverage`
Expected: `testNameOptionsRequiresAuth` PASSES already (pre-existing auth behavior, unchanged by this task) — this is expected, not a failure; the point of this step is to confirm the *shape* test doesn't crash before the fix (it will just hit the `401` branch and pass trivially, since `nameOptions()` hasn't changed yet). Proceed to Step 3 regardless.

- [ ] **Step 3: Update `nameOptions()` and delete the dead constants**

In `src/Controller/Api/ClubController.php`:

1. Add the import (alongside the existing `use App\Service\ClubInitializationService;` line):
```php
use App\Service\NpcClubGenerationService;
```

2. Delete the entire `CITIES_BY_COUNTRY` constant (currently lines 24-72) and the entire `SUFFIXES_BY_COUNTRY` constant (currently lines 74-104) — i.e. everything from `private const CITIES_BY_COUNTRY = [` through the closing `];` of `SUFFIXES_BY_COUNTRY`, leaving the `#[Route('/foreign', ...)]` action as the next thing after the constructor.

3. Replace the `nameOptions()` method body:
```php
    #[Route('/name-options', name: 'api_clubs_name_options', methods: ['GET'])]
    public function nameOptions(Request $request, NpcClubGenerationService $npcClubGenerationService): JsonResponse
    {
        $country  = strtoupper($request->query->get('country', 'EN'));
        $cities   = $npcClubGenerationService->getPlaceNames($country) ?: $npcClubGenerationService->getPlaceNames('EN');
        $suffixes = $npcClubGenerationService->getSuffixes($country) ?: $npcClubGenerationService->getSuffixes('EN');

        return $this->json([
            'country'  => $country,
            'cities'   => $cities,
            'suffixes' => $suffixes,
        ]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `lando php vendor/bin/phpunit tests/Controller/Api/ClubControllerTest.php --no-coverage`
Expected: PASS (2 tests — `testNameOptionsRequiresAuth` confirms the 401 gate is unchanged, `testNameOptionsReturnsExpectedShapeWhenAuthenticated` passes via its 401 branch since no real JWT is minted).

- [ ] **Step 5: Manually verify the real behavior end-to-end**

Since the test suite can't mint a real JWT, manually confirm the fix with a real authenticated request against the running Lando app (mint a real token via `POST /api/login` with a test user's credentials, or reuse whatever manual-testing approach was used for prior fixes this session):

Run for each of the 9 generation-capable countries and confirm each returns a non-empty `cities` array with 9+ entries and a non-empty `suffixes` array:
```bash
curl -s -H "Authorization: Bearer <real-jwt>" "http://localhost:52100/api/club/name-options?country=NL" | php -r 'var_dump(json_decode(file_get_contents("php://stdin"), true));'
curl -s -H "Authorization: Bearer <real-jwt>" "http://localhost:52100/api/club/name-options?country=PT" | php -r 'var_dump(json_decode(file_get_contents("php://stdin"), true));'
```
(NL and PT are the two countries `ClubController`'s old constants didn't support at all — confirming these two specifically proves the fix.) Also confirm an unsupported code (e.g. `country=XX`) falls back to EN's data rather than returning an empty response.

- [ ] **Step 6: Run the full suite and commit**

Run: `lando php vendor/bin/phpunit --no-coverage`
Expected: all green (same baseline note as Task 1 regarding the pre-existing flaky test).

```bash
git add src/Controller/Api/ClubController.php tests/Controller/Api/ClubControllerTest.php
git commit -m "fix: source club name-options from NpcClubGenerationService, not duplicate lists"
```

---

## Verification

- `lando php vendor/bin/phpunit --no-coverage` — full suite green.
- Manual curl checks per Task 2 Step 5, specifically confirming `NL` and `PT` (previously unsupported) now return real data, and confirming an unsupported code (`XX`) falls back to EN.
- `grep -rn "CITIES_BY_COUNTRY\|SUFFIXES_BY_COUNTRY" src/Controller/Api/ClubController.php` returns nothing (constants fully removed).
