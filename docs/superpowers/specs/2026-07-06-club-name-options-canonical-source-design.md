# Club Name Options: Source from NpcClubGenerationService

## Context

`GET /api/club/name-options` already exists (`src/Controller/Api/ClubController.php:120-132`) and is used by the client during club creation — it returns lists of city/place names and club-name suffixes for a given country so the player can pick or generate a suggested club name.

The endpoint currently sources this data from its own private constants (`ClubController::CITIES_BY_COUNTRY`, `ClubController::SUFFIXES_BY_COUNTRY`) — a **separate, duplicate copy** of country-scoped naming data, independent from `src/Service/NpcClubGenerationService.php`'s `PLACE_NAMES_BY_COUNTRY`/`SUFFIXES_BY_COUNTRY` constants (the data actually used when generating real NPC clubs). These two copies have drifted: `ClubController`'s lists only cover 7 countries (EN, ES, DE, FR, IT, AR, BR — missing NL and PT) with shorter city lists, while `NpcClubGenerationService` covers the full 9-country set (ES, EN, DE, IT, FR, BR, AR, NL, PT) that this session's prior fixes (Starter Config, Worldpack Cache, the Generate screen) already established as the canonical "league/club generation capable" country set.

This is the same class of bug fixed twice already this session: a country-scoped list duplicated in a second location, silently drifting out of sync with the canonical source. The fix: point `name-options` at `NpcClubGenerationService` directly and delete the duplicate constants.

## Design

### 1. `NpcClubGenerationService` — two new public getters

`PLACE_NAMES_BY_COUNTRY` and `SUFFIXES_BY_COUNTRY` are currently `private const`. Add two small public methods exposing them for external read access:

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

No fallback logic inside these getters — they return exactly what's configured for the given code, or an empty array if none. This deliberately does **not** touch `generateClubs()`'s own two pre-existing, inconsistent fallback behaviors (place names fall back to generic placeholders like `'Capital'`/`'Northern'`; suffixes have no fallback and would error on an unmapped code) — that's existing behavior, out of scope here.

### 2. `ClubController::nameOptions()` — source from the service

Method-inject `NpcClubGenerationService` (matching the existing method-injection pattern already used in `foreignClubs()` for `NpcClubRepository`). Replace the two local constant lookups with calls to the new getters, keeping:
- The same response shape and key names (`country`, `cities`, `suffixes`) — no client-facing change, only the underlying data source and country coverage change.
- The same fallback-to-EN-if-unsupported behavior the endpoint already has today, now applied against the corrected data source.

```php
#[Route('/name-options', name: 'api_clubs_name_options', methods: ['GET'])]
public function nameOptions(Request $request, NpcClubGenerationService $npcClubGenerationService): JsonResponse
{
    $country  = strtoupper($request->query->get('country', 'EN'));
    $cities   = $npcClubGenerationService->getPlaceNames($country) ?: $npcClubGenerationService->getPlaceNames('EN');
    $suffixes = $npcClubGenerationService->getSuffixes($country) ?: $npcClubGenerationService->getSuffixes('EN');

    return $this->json(['country' => $country, 'cities' => $cities, 'suffixes' => $suffixes]);
}
```

### 3. Delete dead code

Remove `ClubController::CITIES_BY_COUNTRY` and `ClubController::SUFFIXES_BY_COUNTRY` entirely. Confirmed via `grep` that no other file references either constant.

### 4. Net effect

The endpoint goes from covering 7 countries with shorter, independently-maintained city lists to covering the full 9-country canonical set (ES, EN, DE, IT, FR, BR, AR, NL, PT), matching every other "generation-capable countries" surface fixed this session (Starter Config's Country Config checkboxes, Worldpack Cache's country picker, the Generate screen's dropdowns).

## Testing

- **`NpcClubGenerationService` unit tests** (new, added to `tests/Service/NpcClubGenerationServiceTest.php` or a new file): `getPlaceNames()`/`getSuffixes()` return the expected non-empty array for a known country code (e.g. `'ES'`), and an empty array for an unknown code (e.g. `'XX'`).
- **`ClubController` test** (new — no existing test file for this controller): confirms all 9 supported countries return non-empty `cities`/`suffixes` arrays via `GET /api/club/name-options?country=...`, and that an unsupported country code falls back to EN's data. Follow this codebase's existing controller-test conventions (`WebTestCase` where auth/routing needs exercising, matching the style already used for other API controllers in `tests/Controller/Api/`).

## Non-goals

- Not changing `generateClubs()`'s own fallback behavior for missing countries.
- Not renaming the `cities` response key to `places` (kept for backward compatibility with the existing client).
- Not adding new countries to `NpcClubGenerationService` itself — this fix only makes `name-options` consistent with whatever `NpcClubGenerationService` already supports.
