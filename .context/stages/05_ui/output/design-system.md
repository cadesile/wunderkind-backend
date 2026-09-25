# Design System — Admin Panel Theme

This repo is backend-only (see `.context/shared/stack.md`) — the actual
game client (React Native) lives in a separate repo and is out of scope
here. This file documents the custom-themed **EasyAdmin back-office
panel**, which is real and non-trivial, not stock EasyAdmin defaults.

## Canonical token source: `public/admin-theme.css`

Wired in via `src/Controller/Admin/DashboardController.php`
(`configureAssets()` → `Assets::new()->addCssFile('admin-theme.css')`).
Defines tokens as Bootstrap 5 CSS variable overrides (532 lines).

**Color palette** (from the file's own header comment):

| Token | Value |
|---|---|
| greenDark | `#0f1420` |
| greenMid | `#1a2240` |
| tealDark | `#120d18` |
| tealMid | `#2a1e38` |
| tealPanel | `#1e2a50` |
| tealCard | `#1e2448` |
| tealLight / green (accent) | `#3FAA7E` |
| yellow | `#E8CF59` |
| orange | `#e8852a` |
| red | `#C44747` |
| blue | `#3F52A5` |
| plum | `#7D2A60` |
| text | `#e8f0f4` |
| dim text | `#9bb0c4` |
| border | `#080d1a` |

**Typography:** `--bs-body-font-family: 'Space Mono', monospace`;
`'Press Start 2P'` (8-bit/pixel display font) for headings, nav section
labels, and buttons; `'VT323'` also imported. All via Google Fonts
`@import`.

**Shape:** `--bs-border-radius: 0` (and all `-sm/-lg/-xl/-2xl` variants),
reinforced by a global `*, *::before, *::after { border-radius: 0 !important; }`
— the system is deliberately hard-edged/retro; no rounded corners anywhere.

These tokens are applied across sidebar, navbar, cards, tables, forms,
buttons, badges, alerts, dropdowns, modals, pagination, breadcrumbs,
scrollbars, and Select2 — a full theming pass, not a one-off tweak.

**Branding**, also set in `DashboardController.php`:
`setTitle('<img src="/images/logo.png" ...> Build My Club')`,
`setFaviconPath('images/logo.png')`.

## Custom template overrides

- **`templates/bundles/EasyAdminBundle/layout.html.twig`** — overrides
  EasyAdmin's own core layout (`extends '@!EasyAdmin/layout.html.twig'`).
  Overrides `head_favicon` (adds a null-safety guard) and
  `body_javascript` (adds a trophy-image color-filter preview script and a
  custom global delete-confirmation modal, styled with the same
  `var(--bs-...)` tokens as `admin-theme.css`).
- **`templates/admin/form/appearance_theme.html.twig`** +
  **`templates/admin/field/appearance.html.twig`** — a custom EasyAdmin
  form-widget theme, registered per-controller via
  `$crud->addFormTheme('admin/form/appearance_theme.html.twig')` in
  `PlayerCrudController.php`, `ScoutCrudController.php`,
  `StaffCrudController.php`, `AgentCrudController.php`. Replaces EasyAdmin's
  default compound-field rendering with a two-column layout (big live pixel
  sprite preview + Randomise button, plus tabbed swatch/icon-grid pickers
  driven by `public/assets/appearance-widget.js`), rendering the sprite via
  `public/assets/avatar-compositor.js`. Takes a `person_type` form-type
  option (`->setFormType(AppearanceType::class)->setFormTypeOptions([...])`
  — this EasyAdmin version's `setFormType()` takes only the class name) that
  picks the preview's body shape ('player' vs 'staff') without changing what
  fields exist or what's stored.
- **`templates/admin/form/kit_identity_theme.html.twig`** +
  **`templates/admin/field/kit_identity.html.twig`** — the same custom-widget
  pattern as the appearance widget above, registered in
  `NpcClubCrudController.php` for a club's kit + badge `identity`. Three tabs
  — **Kit Home / Kit Away / Badge** — and the live preview itself swaps
  between home-kit-only, away-kit-only, and badge-only content depending on
  the active tab (the appearance widget's preview always shows everything at
  once). The form's own fields are flat and prefixed (`homeKit`,
  `awayPrimary`, ...), nested back into `home`/`away` by `KitIdentityType`'s
  custom `DataMapperInterface` on submit. Shorts/socks render as plain color
  chips (`.ap-chip`), not a kit thumbnail — a full sprite render can't show
  which part-color option is selected, since shorts/socks sit outside the
  shirt-only crop used for kit-style thumbnails. Rendered via
  `public/assets/kit-compositor.js`, picker logic in
  `public/assets/kit-identity-widget.js`; reuses `admin-appearance-widget.css`
  as-is (its `.ap-*` classes, including the newer `.ap-chip*` ones, aren't
  appearance-specific).
- Both custom widgets append a `?v={{ 'now'|date('YmdHis') }}` cache-buster
  to their CSS/JS `asset()` URLs — this repo has no asset-versioning strategy
  configured, so a browser that fetched one of these files before an edit
  will otherwise keep serving the stale copy indefinitely with no visible
  error (the live preview just silently renders blank).

## What's just stock/default (not part of the design system)

`templates/admin/login.html.twig`, `templates/admin/dashboard.html.twig`,
and the various `templates/admin/*_content.html.twig` files are ordinary
content templates that consume the theme above but don't define new
tokens. `public/bundles/easyadmin` and `public/bundles/apiplatform` are
vendor/asset-mapper output. `templates/landing/*` is a separate marketing
landing page with its own sections, unrelated to this admin theme.

## Known issue — not a design-system finding, flagged for visibility

`templates/bundles/EasyAdminBundle/layout.html.twig`'s `body_javascript`
block loads `https://perf-analytics.lndo.site/widget/<id>.js` on every
admin page load. `.lndo.site` is normally a Lando local-dev-only hostname
— unusual for a checked-in template that ships to production. Flagged to
the human for verification; not otherwise acted on.

## Do's / don'ts actually demonstrated by the code

- Every custom EasyAdmin form theme is applied via `addFormTheme(...)`
  per-controller, not globally — consistent across all three controllers
  that use it (Player/Scout/Staff).
- No component was found that deviates from the `--bs-border-radius: 0`
  rule — treat sharp corners as a hard constraint, not a style suggestion.
