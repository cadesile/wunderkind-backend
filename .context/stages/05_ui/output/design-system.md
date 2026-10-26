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
  Overrides `head_favicon` (adds a null-safety guard),
  `body_javascript` (adds a trophy-image color-filter preview script and a
  custom global delete-confirmation modal, styled with the same
  `var(--bs-...)` tokens as `admin-theme.css`), and `header_logo` (adds a
  **live server clock** under the "Build My Club" title). The server runs
  **UTC** and the operator usually does not, so every timestamp in this panel —
  cron runs, deploy logs, sync records — otherwise needs converting before it
  can be read against anything. Rendered server-side as the first paint and the
  no-JS fallback, then ticked once a second from a `data-server-time` anchor:
  the script measures the skew between that stamp and the browser's own clock
  and applies it, so a machine with a wrong clock still shows true server time,
  and formatting is pinned to `timeZone: 'UTC'` rather than `toLocaleString`,
  which would silently show the viewer's zone. Note `header_logo` is extended by
  **restating EasyAdmin's anchor verbatim** (Twig cannot append to a block), so
  an upstream change to that markup can leave the override out of step —
  `tests/Controller/Admin/SidebarServerClockTest.php` renders the real layout to
  catch that. Styles live in `admin-theme.css` under "Server clock (sidebar)";
  it hides on the 64px compact rail alongside `.logo-custom`.
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
- **`public/assets/kit-compositor.js` is generated too**, by
  `bash scripts/build-kit-compositor.sh` from `assets/Components/KitBadgeV2/kit16.ts`
  (the 16-bit kit+badge sprite, 2026-10-13), gated on an 18-case pixel-hash
  fixture. Identical mechanism to the avatar compositor below, kept as a
  separate script because the sources and fixture layouts differ.
  `composeKitSvg(config, type, crop, scale)` is unchanged, with `'large'`/
  `'small'` now aliasing `'full'`/`'shirt'`, and `KitConfig` itself did not
  change — no stored club identity needs migrating.
- **`public/assets/avatar-compositor.js` is generated, not hand-written**
  (changed 2026-10-13, when the app replaced its 8-bit `sprite.ts` with the
  16-bit `avatar-sprite/avatar16.ts`). `bash scripts/build-avatar-compositor.sh`
  runs the app's sprite source through esbuild — type-stripping only — and gates
  on its pixel-hash parity fixture, so the repo's long-standing "port it
  verbatim" rule is now mechanised instead of hand-transcribed. The one
  hand-written piece is `scripts/avatar-compositor/entry.ts`, which adapts the
  renderer to the pre-existing `window.composePlayerSpriteSvg(config, type,
  crop, scale, opts)` contract (old `'small'`/`'large'` crop names still
  accepted; `scale` halved internally for the 2x grid). The sprite's build tiers
  (`height`/`weight` as small/medium/large) are **derived at render time** from
  `Player`'s real cm/kg columns via `AppearanceType`'s `height_cm`/`weight_kg`
  options — never written into the stored `appearance` JSON, which would
  duplicate data the Player entity already owns. They reach the renderer as
  `opts.height`/`opts.weight` **props on the compose call**, which take either a
  tier directly or a raw cm/kg number (bands: 145-169 / 170-184 / 185-200 cm,
  58-69 / 70-84 / 85-100 kg; `heightCm`/`weightKg` survive as aliases).
  `opts.outline` **defaults to `false`** (changed 2026-10-13) even though
  `avatar16.ts`'s own `renderAvatar` defaults it to `true` — admin previews and
  the landing page both want the unoutlined look, matching `composeKitSvg`'s
  own no-outline default; pass `{outline: true}` to opt back in.
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
landing page with its own sections, unrelated to this admin theme — see
the section below for its own token source.

## Second design system: the marketing landing page

Entirely separate from the admin theme above; the two share no tokens.

**Canonical token source: the `:root` block at the top of
`public/landing.css`**, whose own header comment declares itself the style
guide: *"retro pixel-art, dark green, zero border-radius, Press Start 2P /
VT323 / Space Mono. New sections build from these classes."* Key tokens:
`--bg #0d2e28`, `--bg-mid #123a33`, `--panel #17493f`, `--border #051512`,
`--gold #f5c842`, `--gold-dim #c9a134`, `--mint #3FAA7E`, `--cyan #4fb8c9`,
`--red #C44747`, `--text #e4ede4`, `--text-muted #7dac9f`.

Same hard constraints as the admin theme: `border-radius: 0 !important`
globally, and `image-rendering: pixelated` on every sprite. A CRT scanline
overlay is painted via `body::before`.

**Redesigned 2026-10** to `design/Main.dc.html` in the external
`assets/bmc-landing-export` drop. Section order is now, top to bottom:
header → `#top` → `#telemetry` → `#pillars` → `#spotlight` → `#role` →
`#journey` → `#facilities` → `#dressing-room` → `#leagues` → `#scouting` →
no-pay-to-win band → `#owner-spotlight` → `#testers` → closing CTA → footer.
Two sections that were nested children became top level: the telemetry terminal
(was `_chairmans_terminal`, a hero widget) and the Rich List (was included from
`_story`, inside Journey Chapter 01). Thirteen sections were dropped from
`index.html.twig` — `_ticker`, `_screens` and its `_shot` macro, `_stats_band`,
`_features`, `_excursions`, `_archetypes`, `_depth`, `_tick`, `_consensus`,
`_video`, `_community`, `_leaderboards` — and **deliberately left on disk**:
restoring one is a single include line, and `_shot`/`public/screenshots/*` are
still referenced by the orphans. `_story` became `_journey` and
`_chairmans_terminal` became `_telemetry`.

**No screenshots anywhere on the page.** Every image is now a sprite drawn by a
compositor — that is the redesign's central rule. `images/match-ticker.gif` (a
capture of the matchday ticker, previously in `_leagues`) went with them and is
still on disk, unreferenced. Two kinds of sprite surface, and keeping them
distinct is load-bearing:
- **Real data** — the Club Spotlight, its Facilities panel, the Rich List and
  the telemetry feed's club badges, all from `data-*` attributes written from
  the `ClubSpotlight` row, `OwnerSpotlightService` and the telemetry snapshot.
  These live inside ringed panels.
- **Decorative** — the journey, dressing-room, scouting and leagues scenes, plus
  the Facilities section's four sector cards. Generated from seeded random
  configs by the `Scenes` module in `landing.js`, ported from the drop's
  `design/random.js.src` (which is the spec for "random within role": levels
  1-5, facilities on 85%, construction 12%). Seeded **once per page load**, so a
  scene is stable while the page is open and different on the next reload — the
  one visible tell that it is not somebody's real club. Nothing generated is
  ever labelled with a club or owner name. `design/sprites.js` is NOT shipped
  (it is built from an older facility engine), and `window.BMC_ISO` is replaced
  by the real `compose*Svg`.

**The landing page loads `facility-compositor.js`, not `stadium-compositor.js`**
(changed 2026-10). One bundle, all four sites, and `composeStadiumSvg` is
byte-identical between the two, so the switch in `base.html.twig` needed no call-site
edits. **Nothing in the repo loads `stadium-compositor.js` any more** — it, its
build script and its `entry.ts` are candidates for retirement, which the
facility entry's own docblock says is the landing task's call; it was left in
place because deleting it is a build-script change.

**Every sprite mounts through `IntersectionObserver`** (`mountWhenVisible()` in
`landing.js`). A full facility site is ~200KB of path data and the page draws
more than thirty, so each box reserves its aspect ratio in CSS and the sprite
lands later. No observer support means mount immediately.

**Facility tile structure comes from `window.FACILITY_SITES`, never from Twig
or a hand-written list** — the bundle's own export of which facilities each site
has and which game slug draws as which sprite id, the browser-side twin of
`FacilitySpriteMap`. The payload supplies state (built, level, accent), never
structure. Its `labels` are i18n **keys**, not display text, so `landing.js`
title-cases the game slug and overrides only the handful that read wrong.
`FacilitySites.stateOf()` is the one place built-ness and level are read, and it
reports the **game's** level (1-5), halving the sprite's 1-10 — the number a
player recognises. The four stadium stands are the one group with no boolean
beside their level; `>= 2` is a sound test there *only* because
`spriteLevel()` is `max(1, min(10, L*2))`, so an unbuilt stand is exactly 1 and
any real level is at least 2.

**The telemetry terminal has three states**, per `design/States.dc.html`: not
generated (`getId() === null` — tiles render em-dashes, never zeros, because a
real pyramid-wide zero and "never measured" look identical as numerals), stale,
and active. `_telemetry.html.twig` computes staleness in Twig with its own
`1800` second threshold, **duplicating `Api\TelemetryController`'s
`STALE_AFTER_SECONDS`** — the page renders from the entity, which carries no
staleness accessor, and never round-trips to its own API. Keep the two in step.

**Feed lines are real `<button>`s** with a pause control, and `landing.js` starts
the rotation paused under `prefers-reduced-motion`, adding `.is-static` so the
whole list renders instead of one frozen line. A media query cannot stop a
`setInterval`, which is why that lives in JS.

**Store CTAs are driven by `data-store-link`, not element ids.** Five roles —
`android`, `ios`, `android-missing`, `ios-fallback`, `pending` — all shipped
hidden and revealed by `GET /api/app-links`. The previous version named five
specific ids and called `getElementById` unguarded on each, so adding a sixth
button meant editing `landing.js` and removing one threw a `TypeError` that took
the social links down with it.

Shared conventions worth knowing before adding a section:

- **Pixel sprites are composed client-side from `data-*` attributes**, never
  server-rendered as markup. `public/assets/kit-compositor.js`
  (`composeKitSvg()` — club kits/badges), `avatar-compositor.js`
  (`composePlayerSpriteSvg()` — people), `stadium-compositor.js`
  (`composeStadiumSvg()`) and `trophy-compositor.js` (`composeTrophySvg()`) all
  load before `landing.js` (see `templates/landing/base.html.twig`). All four
  are verbatim ports of the client's own sprite code — and all four are
  GENERATED, so you never port one by hand; see the build-rule bullet below.
- **`clubBadgeIconsHtml()` in `landing.js` is the one club-identity renderer**,
  shared by the ticker, the Owner Spotlight, the community-stats lists and the
  global leaderboards. It emits badge + kit from a
  `{home, away, badge}` object and falls back to `composeKitSvg()`'s own
  defaults, so every club draws something even before it is customized. Rule:
  **any club listed anywhere on the page shows at least its badge**; the kit is
  hidden by CSS below 560px, where the row has no room for both.
- **`clubCountryChipHtml()`** renders a club's nationality as a 2-letter code
  chip (`.club-flag`), never an emoji flag: `App\Enum\Country` codes include
  `EN`, which has no regional-indicator equivalent, and emoji flags render
  inconsistently across platforms at chip size.
- **Card families share a visual language but not a class namespace.** The Owner
  Spotlight (`.ospot-*`) deliberately borrows the Club Spotlight's
  (`.spotlight-*`) gold-ringed panel, header/footer bands and Press Start 2P
  micro-labels while keeping its own prefix, so restyling one never silently
  restyles the other.
- **Trophies are drawn by a sprite compositor, not recoloured artwork.** They
  used to be black silhouette SVGs (`/images/trophies/trophy-N.svg`) recoloured
  by a CSS filter chain duplicated across
  `templates/admin/leagues_content.html.twig` and
  `templates/landing/sections/_spotlight.html.twig`, with a comment on each
  telling readers to keep the two in step. Both are gone: every surface now
  calls `composeTrophySvg()` from `public/assets/trophy-compositor.js` (a
  GENERATED bundle — see the compositor note below), so there is one renderer
  and nothing to keep in step. Three surfaces use it: the per-tier defaults
  table and league cards on the admin leagues screen, the `TrophyConfigType`
  field widget, and the landing page's honours board.
- **Four generated sprite compositors, one build rule.**
  `public/assets/{avatar,kit,trophy,stadium}-compositor.js` are each built from
  a shared `assets/Components/*` TypeScript sprite by
  `scripts/build-{avatar,kit,trophy,stadium}-compositor.sh` (esbuild
  type-stripping only), gated on that sprite's own pixel-hash parity fixture.
  **Never hand-edit one**; change the sprite and rebuild. Each script is
  deliberately separate and self-contained rather than factored into a shared
  one — the four differ in where their source lives and in how their fixtures
  are shaped.
- **The facility compositor is the one built from a multi-file drop**, and as of
  2026-10 it is what the landing page loads. Its source is
  `assets/Components/facilitiesV2/facilities/` — all four site sprites plus the
  shared `isoEngine.ts` they draw through, bundled together because that engine
  is ~32KB and four per-site bundles would ship four copies of it. The build
  gates on the drop's 301-case pixel-hash fixture and also generates
  `src/Service/Facility/FacilitySpriteMap.php`. The drop's `look="v1"` legacy
  tree is neither checked nor shipped. `build-stadium-compositor.sh` and
  `public/assets/stadium-compositor.js` still exist but nothing loads them —
  that pair bundled the stadium alone, back when nothing here rendered the other
  three sites.
- **Stadium levels are the sprite's 1-10, not the backend's 1-5.** Every
  `*Level` field in `StadiumConfig` — stands and the shop/museum/car park alike
  — runs 1-10, while `FacilityTemplate` levels run 1-5, so
  `ClubSpotlightService::buildStadiumConfig()` doubles each one. (The buildings
  ran 1-5 in the v1 sprite and were passed through raw; v2 redrew them for 10
  levels.) The levels also floor at 1 so the renderer never indexes a size table
  out of range, which is precisely why the separate `shop`/`museum`/`carpark`
  booleans have to carry "not built at all".

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
