# Worldpack Cache Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Worldpack Cache" admin page under the Configuration sidebar section that lists all `CountryWorldPackCache` entries with per-entry stats and actions to delete individual entries, delete all entries for a country, or regenerate a country's cache.

**Architecture:** A new `WorldPackController` (extending `AbstractDashboardController`) owns all four routes. The existing `CountryWorldPackCacheRepository` gets one new query method. A single Twig template renders the status grid, detail table, and action forms. The sidebar link is added to `DashboardController::configureMenuItems()`.

**Tech Stack:** PHP 8.4, Symfony 8, EasyAdmin 5, Doctrine ORM 3, Twig, Bootstrap 5 (as used by EasyAdmin layout).

---

## File Map

| Action | File |
|--------|------|
| Modify | `src/Repository/CountryWorldPackCacheRepository.php` |
| Create | `src/Controller/Admin/WorldPackController.php` |
| Create | `templates/admin/worldpack_cache.html.twig` |
| Modify | `src/Controller/Admin/DashboardController.php` (menu only) |

---

### Task 1: Add `findAllOrderedByCountryAndTier()` to the repository

**Files:**
- Modify: `src/Repository/CountryWorldPackCacheRepository.php`

- [ ] **Step 1: Add the query method**

Open `src/Repository/CountryWorldPackCacheRepository.php` and add after `deleteByCountry()`:

```php
/**
 * Returns all cache entries ordered by country (ASC) then tier (ASC).
 *
 * @return CountryWorldPackCache[]
 */
public function findAllOrderedByCountryAndTier(): array
{
    return $this->createQueryBuilder('c')
        ->orderBy('c.country', 'ASC')
        ->addOrderBy('c.tier', 'ASC')
        ->getQuery()
        ->getResult();
}
```

- [ ] **Step 2: Verify the container compiles**

```bash
lando php bin/console debug:container CountryWorldPackCacheRepository
```

Expected: one service entry listed, no errors.

- [ ] **Step 3: Commit**

```bash
git checkout -b feat/worldpack-cache-admin
git add src/Repository/CountryWorldPackCacheRepository.php
git commit -m "feat: add findAllOrderedByCountryAndTier to CountryWorldPackCacheRepository"
```

---

### Task 2: Create `WorldPackController`

**Files:**
- Create: `src/Controller/Admin/WorldPackController.php`

- [ ] **Step 1: Create the controller file**

Create `src/Controller/Admin/WorldPackController.php` with the full content below:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CountryWorldPackCacheRepository;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class WorldPackController extends AbstractDashboardController
{
    public function __construct(
        private readonly EntityManagerInterface          $em,
        private readonly CountryWorldPackCacheRepository $cacheRepository,
        private readonly WorldPackCacheService           $worldPackCacheService,
    ) {}

    // ── List ─────────────────────────────────────────────────────────────

    #[Route('/admin/worldpack-cache', name: 'admin_worldpack_cache')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        $rawEntries = $this->cacheRepository->findAllOrderedByCountryAndTier();

        $entries   = [];
        $byCountry = [];

        foreach ($rawEntries as $entry) {
            $payload     = $entry->getPayload();
            $clubCount   = count($payload['clubs'] ?? []);
            $playerCount = array_sum(
                array_map(fn($c) => count($c['players'] ?? []), $payload['clubs'] ?? [])
            );

            $entries[] = [
                'id'          => (string) $entry->getId(),
                'country'     => $entry->getCountry(),
                'tier'        => $entry->getTier(),
                'clubCount'   => $clubCount,
                'playerCount' => $playerCount,
                'generatedAt' => $entry->getGeneratedAt(),
            ];

            $byCountry[$entry->getCountry()][$entry->getTier()] = true;
        }

        return $this->render('admin/worldpack_cache.html.twig', [
            'entries'      => $entries,
            'byCountry'    => $byCountry,
            'totalEntries' => count($entries),
        ]);
    }

    // ── Delete single entry ───────────────────────────────────────────────

    #[Route('/admin/worldpack-cache/delete/{id}', name: 'admin_worldpack_delete_entry', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteEntry(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('worldpack_delete_entry', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $entry = $this->cacheRepository->find($id);

        if ($entry === null) {
            $this->addFlash('warning', "Cache entry {$id} not found — it may have already been deleted.");
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $label = "{$entry->getCountry()} / Tier {$entry->getTier()}";
        $this->em->remove($entry);
        $this->em->flush();

        $this->addFlash('success', "Deleted cache entry: {$label}.");
        return $this->redirect($this->generateUrl('admin_worldpack_cache'));
    }

    // ── Delete all entries for a country ─────────────────────────────────

    #[Route('/admin/worldpack-cache/delete-country', name: 'admin_worldpack_delete_country', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteCountry(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('worldpack_delete_country', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $country = strtoupper(trim($request->request->getString('country')));

        if (strlen($country) !== 2) {
            $this->addFlash('danger', 'Invalid country code.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $deleted = $this->worldPackCacheService->deleteByCountry($country);
        $this->addFlash('success', "Deleted {$deleted} cache entry/entries for {$country}.");

        return $this->redirect($this->generateUrl('admin_worldpack_cache'));
    }

    // ── Regenerate cache for a country ────────────────────────────────────

    #[Route('/admin/worldpack-cache/regenerate', name: 'admin_worldpack_regenerate', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function regenerate(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('worldpack_regenerate', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $country = strtoupper(trim($request->request->getString('country')));

        if (strlen($country) !== 2) {
            $this->addFlash('danger', 'Invalid country code.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input  = new ArrayInput([
            'command' => 'app:worldpack:warm',
            'country' => $country,
            '--force' => true,
        ]);
        $output = new BufferedOutput();

        try {
            $exitCode = $application->run($input, $output);
            $text     = trim($output->fetch());

            if ($exitCode === 0) {
                $this->addFlash('success', "Worldpack regenerated for {$country}.");
            } else {
                $this->addFlash('danger', "Regeneration failed for {$country}: {$text}");
            }

            if ($text !== '') {
                $this->addFlash('info', nl2br(htmlspecialchars($text)));
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', "Error running worldpack command: " . $e->getMessage());
        }

        return $this->redirect($this->generateUrl('admin_worldpack_cache'));
    }
}
```

- [ ] **Step 2: Verify routes are registered**

```bash
lando php bin/console debug:router | grep worldpack
```

Expected output (4 lines):
```
admin_worldpack_cache           GET    /admin/worldpack-cache
admin_worldpack_delete_entry    POST   /admin/worldpack-cache/delete/{id}
admin_worldpack_delete_country  POST   /admin/worldpack-cache/delete-country
admin_worldpack_regenerate      POST   /admin/worldpack-cache/regenerate
```

- [ ] **Step 3: Commit**

```bash
git add src/Controller/Admin/WorldPackController.php
git commit -m "feat: add WorldPackController with worldpack cache admin routes"
```

---

### Task 3: Create the Twig template

**Files:**
- Create: `templates/admin/worldpack_cache.html.twig`

- [ ] **Step 1: Create the template**

Create `templates/admin/worldpack_cache.html.twig`:

```twig
{% extends '@EasyAdmin/layout.html.twig' %}

{% set COUNTRIES = {
    'EN': 'England',   'IT': 'Italy',      'DE': 'Germany',
    'ES': 'Spain',     'BR': 'Brazil',     'AR': 'Argentina',
    'NL': 'Netherlands','FR': 'France',    'PT': 'Portugal',
    'NG': 'Nigeria',   'GH': 'Ghana',      'JP': 'Japan',
    'KR': 'South Korea','SE': 'Sweden',    'DK': 'Denmark',
    'IE': 'Ireland',   'CI': 'Ivory Coast','SN': 'Senegal',
    'CN': 'China'
} %}

{% block content %}
<div class="row g-4 mt-1">

    {# ── Header ─────────────────────────────────────────────────────────── #}
    <div class="col-12">
        <h5 class="fw-semibold mb-0">Worldpack Cache</h5>
        <p class="text-muted small mb-0">
            Pre-generated NPC league packs keyed by country + tier.
            Used at game initialisation to skip expensive world-pack generation on the device.
        </p>
    </div>

    {# ── Status grid ─────────────────────────────────────────────────────── #}
    <div class="col-12">
        <div class="card">
            <div class="card-header py-2 d-flex align-items-center gap-2">
                <i class="fa fa-database text-muted"></i>
                <span class="fw-semibold small">Cache Status</span>
                <span class="ms-auto badge bg-secondary">{{ totalEntries }} total entries</span>
            </div>
            <div class="card-body p-0">
                {% if byCountry is empty %}
                    <p class="text-muted small p-3 mb-0">No cache entries found. Use the Regenerate panel below to warm the cache for a country.</p>
                {% else %}
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Country</th>
                                    {% for tier in 1..8 %}
                                        <th class="text-center">T{{ tier }}</th>
                                    {% endfor %}
                                    <th class="text-end pe-3">Cached</th>
                                </tr>
                            </thead>
                            <tbody>
                                {% for code, tiers in byCountry %}
                                    <tr>
                                        <td class="ps-3 fw-semibold">
                                            {{ COUNTRIES[code] ?? code }}
                                            <span class="text-muted fw-normal">({{ code }})</span>
                                        </td>
                                        {% for tier in 1..8 %}
                                            <td class="text-center">
                                                {% if tiers[tier] is defined %}
                                                    <span class="badge bg-success bg-opacity-75">✓</span>
                                                {% else %}
                                                    <span class="text-muted">—</span>
                                                {% endif %}
                                            </td>
                                        {% endfor %}
                                        <td class="text-end pe-3 fw-semibold">{{ tiers|length }} / 8</td>
                                    </tr>
                                {% endfor %}
                            </tbody>
                        </table>
                    </div>
                {% endif %}
            </div>
        </div>
    </div>

    {# ── Entry detail table ──────────────────────────────────────────────── #}
    {% if entries is not empty %}
    <div class="col-12">
        <div class="card">
            <div class="card-header py-2 d-flex align-items-center gap-2">
                <i class="fa fa-list text-muted"></i>
                <span class="fw-semibold small">All Entries</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Country</th>
                                <th class="text-center">Tier</th>
                                <th class="text-center">Clubs</th>
                                <th class="text-center">Players</th>
                                <th>Generated</th>
                                <th class="pe-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {% for entry in entries %}
                                <tr>
                                    <td class="ps-3 fw-semibold">
                                        {{ COUNTRIES[entry.country] ?? entry.country }}
                                        <span class="text-muted fw-normal">({{ entry.country }})</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary bg-opacity-75">{{ entry.tier }}</span>
                                    </td>
                                    <td class="text-center">{{ entry.clubCount }}</td>
                                    <td class="text-center">{{ entry.playerCount }}</td>
                                    <td class="text-muted">{{ entry.generatedAt|date('Y-m-d H:i') }}</td>
                                    <td class="pe-3 text-end">
                                        <form method="POST"
                                              action="{{ path('admin_worldpack_delete_entry', {id: entry.id}) }}"
                                              onsubmit="return confirm('Delete {{ COUNTRIES[entry.country] ?? entry.country }} Tier {{ entry.tier }} cache entry?')">
                                            <input type="hidden" name="_token" value="{{ csrf_token('worldpack_delete_entry') }}">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            {% endfor %}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    {% endif %}

    {# ── Regenerate ──────────────────────────────────────────────────────── #}
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header py-2 d-flex align-items-center gap-2">
                <i class="fa fa-rotate text-primary"></i>
                <span class="fw-semibold small">Regenerate Country Cache</span>
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <p class="text-muted small mb-0">
                    Deletes all existing entries for the selected country then re-runs
                    <code>app:worldpack:warm --force</code> to rebuild from scratch.
                    Requires leagues to be generated for the country first.
                </p>
                <div class="mt-auto">
                    <form method="POST" action="{{ path('admin_worldpack_regenerate') }}">
                        <input type="hidden" name="_token" value="{{ csrf_token('worldpack_regenerate') }}">
                        <div class="mb-3">
                            <label for="regen_country" class="form-label fw-semibold small">Country</label>
                            <select id="regen_country" name="country" class="form-select form-select-sm">
                                {% for code, name in COUNTRIES %}
                                    <option value="{{ code }}">{{ name }} ({{ code }})</option>
                                {% endfor %}
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa fa-rotate me-1"></i>Regenerate (--force)
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {# ── Delete country ──────────────────────────────────────────────────── #}
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header py-2 d-flex align-items-center gap-2">
                <i class="fa fa-trash text-danger"></i>
                <span class="fw-semibold small">Delete All for Country</span>
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <p class="text-muted small mb-0">
                    Removes all cache entries for the selected country without regenerating.
                    The cache will be rebuilt on next game initialisation for that country.
                </p>
                <div class="mt-auto">
                    <form method="POST"
                          action="{{ path('admin_worldpack_delete_country') }}"
                          onsubmit="return confirm('Delete all cache entries for ' + this.country.options[this.country.selectedIndex].text + '?')">
                        <input type="hidden" name="_token" value="{{ csrf_token('worldpack_delete_country') }}">
                        <div class="mb-3">
                            <label for="del_country" class="form-label fw-semibold small">Country</label>
                            <select id="del_country" name="country" class="form-select form-select-sm">
                                {% for code, name in COUNTRIES %}
                                    <option value="{{ code }}">{{ name }} ({{ code }})</option>
                                {% endfor %}
                            </select>
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="fa fa-trash me-1"></i>Delete All
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
{% endblock %}
```

- [ ] **Step 2: Verify the template renders (no Twig errors)**

With Lando running, open in a browser:
```
http://wunderkind-backend.lndo.site/admin/worldpack-cache
```

Expected: EasyAdmin layout with the Worldpack Cache page. No Twig exceptions.
If the cache table is empty, only the header card and action panels are visible (the entry table is hidden by `{% if entries is not empty %}`).

- [ ] **Step 3: Commit**

```bash
git add templates/admin/worldpack_cache.html.twig
git commit -m "feat: add worldpack_cache admin template"
```

---

### Task 4: Add sidebar menu item in DashboardController

**Files:**
- Modify: `src/Controller/Admin/DashboardController.php`

- [ ] **Step 1: Add the menu item**

In `src/Controller/Admin/DashboardController.php`, find `configureMenuItems()`. Locate the Configuration section:

```php
yield MenuItem::section('Configuration');
yield MenuItem::linkToRoute('Starter Config', 'fa fa-flag', 'admin_starter_config');
yield MenuItem::linkToRoute('Game Config', 'fa fa-sliders', 'admin_game_config');
yield MenuItem::linkToRoute('Pool Config', 'fa fa-layer-group', 'admin_pool_config');
yield MenuItem::linkToRoute('Import / Export', 'fa fa-file-arrow-up', 'admin_config_content');
```

Add the Worldpack Cache entry after Pool Config:

```php
yield MenuItem::section('Configuration');
yield MenuItem::linkToRoute('Starter Config', 'fa fa-flag', 'admin_starter_config');
yield MenuItem::linkToRoute('Game Config', 'fa fa-sliders', 'admin_game_config');
yield MenuItem::linkToRoute('Pool Config', 'fa fa-layer-group', 'admin_pool_config');
yield MenuItem::linkToRoute('Worldpack Cache', 'fa fa-database', 'admin_worldpack_cache');
yield MenuItem::linkToRoute('Import / Export', 'fa fa-file-arrow-up', 'admin_config_content');
```

- [ ] **Step 2: Verify the sidebar link appears**

Reload the admin dashboard and confirm "Worldpack Cache" appears in the Configuration section of the sidebar, and clicking it navigates to `/admin/worldpack-cache` without errors.

- [ ] **Step 3: Commit**

```bash
git add src/Controller/Admin/DashboardController.php
git commit -m "feat: add Worldpack Cache link to admin sidebar Configuration section"
```

---

### Task 5: Manual smoke test all actions

- [ ] **Step 1: Test the list page**

Navigate to `/admin/worldpack-cache`. Verify:
- Status grid shows ✓ for cached tiers and — for missing ones
- Entry table shows country, tier, club count, player count, generated timestamp
- Both action panels (Regenerate, Delete All) are visible

- [ ] **Step 2: Test delete single entry**

Click the trash icon on any entry. Confirm the JS confirm dialog appears. Click OK. Verify:
- Flash success message appears: "Deleted cache entry: XX / Tier N."
- That row is gone from the table

- [ ] **Step 3: Test delete all for country**

Select a country in "Delete All for Country", submit. Confirm the JS dialog. Verify:
- Flash success with deleted count
- All rows for that country are gone from the table and status grid

- [ ] **Step 4: Test regenerate**

Select a country that has leagues seeded, submit Regenerate. Verify:
- Flash success: "Worldpack regenerated for XX."
- Flash info with command output
- Cache entries for that country reappear in the table

- [ ] **Step 5: Test regenerate with no leagues**

Select a country that has no leagues (e.g. CN if not seeded). Verify:
- Flash danger: "Regeneration failed for CN: No leagues found..."
- No entries added

- [ ] **Step 6: Open PR**

```bash
git push -u origin feat/worldpack-cache-admin
gh pr create --title "feat: Worldpack Cache admin page" --body "Adds WorldpackController with list, delete-entry, delete-country, and regenerate routes. Sidebar link added under Configuration."
```
