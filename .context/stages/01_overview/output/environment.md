# Dev Environment

See `.context/shared/stack.md` for language/framework/database facts —
not restated here.

## Local dev — Lando (`.lando.yml`)

- Recipe: `symfony`, nginx, webroot `public/`
- **PHP 8.5.7, set by an image override — not by the recipe's `php:` key.** That key
  still reads `'8.4'` on purpose: Lando 3.25.6's `symfony` builder keeps its own
  version allowlist that stops at 8.4, and `php: '8.5'` aborts `lando start` with
  `symfony-php version 8.5 is not supported`. The real version comes from
  `services.appserver.overrides.image: devwithlando/php:8.5-fpm-7`. Lando can only
  boot images from that family (`/lando-entrypoint.sh` lives inside them, so the
  official `php:` images are not substitutable), and the family is rebuilt only every
  few months — hence 8.5.7 (Jun 2026) rather than the `Dockerfile`'s 8.5.12. **Local
  dev therefore lags upstream security patches by design; treat it as unpatched.**
- Lando's images ship **no composer binary**, so the `composer` tooling command needs
  the `build_as_root` installer step in `.lando.yml` — without it `lando composer`
  fails with `composer: executable file not found in $PATH`.
- Database service `database`: Postgres 16, forwarded to host port 5433,
  creds `wunderkind`/`wunderkind`, db name `wunderkind`
- App server exposed on `127.0.0.1:52100:80`
- `post-start` event auto-generates a JWT keypair into `.env.local` if
  `JWT_SECRET_KEY` isn't already set — first-run bootstrap for
  `lexik/jwt-authentication-bundle`
- Tooling shortcuts: `lando php`, `lando composer`, `lando symfony` (runs
  `php bin/console`), `lando psql`

## Keeping PHP and dependencies patched

- **Audit with `lando composer audit`** (0 advisories as of 2026-10-21). Composer blocks
  advisory-affected versions from even loading (`policy.advisories.block`, default on), so a
  `MAJOR.MINOR.*` pin can make the whole resolve fail as a unit the moment every version on
  that branch is affected and the fix lands only on the next minor — which is exactly what
  forced Symfony 8.0.* -> 8.1.*. See `.context/shared/stack.md`'s dependency-floor bullet.
  Consequence: a red `composer audit` may need a **minor** bump, not a patch bump; don't
  assume the pin can hold.
- **Two PHP versions are in play and they are not the same** — Lando 8.5.7, deployed 8.5.12
  (see above). Verify a PHP upgrade in BOTH: `lando php vendor/bin/phpunit --no-coverage`
  for the suite, and `docker build .` for the image, because the base image breaks
  independently of the code (the PHP 8.5 opcache trap below was invisible to the suite and
  failed only the build).
- **Upgrade order that actually catches things:** bump the `Dockerfile` pin -> `docker build .`
  -> bump `.lando.yml`'s image override -> `lando rebuild -y` -> `lando composer update`
  -> `lando composer audit` -> full suite -> `lando php bin/console lint:container`.

## Developing against realistic data — `scripts/pull-prod-db.sh`

**Trigger phrase**: when the human says something like "pull prod DB", "pull the prod
database", or "refresh my local DB from prod", that means: run `bash
scripts/pull-prod-db.sh --yes` now, in this session — don't just point at the script,
actually run it (the human has already made the PII call below; re-confirming in a shell
prompt the agent itself would be driving is redundant). If the human instead says they
want a *fresh/empty/clean* local DB, that's `scripts/reset_and_seed.sh`, a different
script — don't conflate the two just because both touch the local database.

- Pulls a `pg_dump` from the production Hetzner box (`docs/deploy/hetzner.md`) over SSH
  and restores it into the local Lando database, **replacing** whatever's there.
- **Raw, not scrubbed — a deliberate, explicit human decision** (confirmed via
  `AskUserQuestion` when this script was built, 2026-10-03), not a default assumption to
  revisit unprompted: the restored copy includes real user PII verbatim (emails, owner
  identity, GDPR `DeletionRequest` rows). OAuth tokens on `social_account_connection`
  decrypt to nothing locally (encrypted with the prod-only `SOCIAL_TOKEN_ENCRYPTION_KEY`,
  which the local env doesn't have) and FCM device tokens can't receive a push triggered
  from a local environment — both effectively inert once local, not live secrets.
- Dump files land under `var/prod-dumps/` (gitignored via the existing root `/var/`
  pattern) and are deleted after a successful import by default — pass `--keep-dump` to
  retain one, then `--from-dump var/prod-dumps/<file>.sql` to re-restore it without
  re-pulling from prod (useful when iterating on the restore step itself, or re-running
  after a `reset_and_seed.sh` wipe).
- Requires SSH access to the production host and Lando running locally
  (`lando start`) — same SSH key already used for manual deploy/runbook operations.
- Handles two environment quirks that aren't obvious from the script alone if it ever
  needs debugging: Lando's `postgres:16` service does **not** make the app DB user
  (`wunderkind`) a superuser (unlike prod's `POSTGRES_USER` convention) — dropping/
  recreating the database must connect as the built-in `postgres` role instead; and newer
  `pg_dump` point releases (confirmed on prod: 16.15) wrap dumps in `\restrict`/
  `\unrestrict` meta-commands that an older local `psql` (confirmed: 16.6) doesn't
  recognize — the script strips those lines before restoring regardless of which side is
  newer.
- Full test suite is unaffected either way: functional tests run against the separate
  `wunderkind_test` database (see root `CLAUDE.md`'s Testing section), never the `wunderkind`
  dev database this script touches.

## ⚠️ `wunderkind_test` is NOT migration-tracked — a new migration never reaches it

It is built by `doctrine:schema:create` from **entity metadata**, so
`doctrine:migrations:migrate --env=test` fails on already-applied DDL and a migration you have
just written has no effect there at all. The suite then fails in ways that look nothing like
your change.

**What that looks like (2026-11-02):** a full run reported **36 failures** across WorldPack,
StarterConfig and competition tests. Thirty-five were one missing column
(`starter_config.setup_tutorial_config`, added by an earlier commit and never applied here)
cascading through everything that loads a `StarterConfig`. Exactly one was real. The noise is
the danger: it is easy to read a wall of red as "my change broke everything" or, worse, as
"this suite is always flaky" and push past it.

**It happened again (2026-11-20).** `scout.ability` (`Version20261120100000`, A26) reached
`wunderkind` but not `wunderkind_test`, and a full run reported **20 errors and 5 failures**.

⚠️ **But the attribution here is different from 2026-11-02's, and that is the more useful
lesson.** Only **4** of those 20 errors were the missing column (`column "ability" does not
exist`). The other 16 were an unrelated mistake in the same change — a new constructor argument
on `ScoutGenerationService`, which two test classes instantiate by hand. Measured by difference:
adding the column took the run from 20 errors to 16.

So **this failure mode does not always look like a wall of identical red.** 2026-11-02's did (35
of 36 from one column) and that shape is what this section originally trained people to expect;
here the missing column was a *minority* of the noise and would have been easy to dismiss as a
side effect of the real bug. **Check `information_schema.columns` for your new column regardless
of how the failures look**, because it is a one-command check and the alternative is attributing
4 errors to the wrong cause.

Applied by hand in the shape this section prescribes and the compat doc's checklist point 5
requires, since the column is `NOT NULL`:

```bash
lando php bin/console dbal:run-sql "ALTER TABLE scout ADD COLUMN IF NOT EXISTS ability INT" --env=test

lando php bin/console dbal:run-sql "UPDATE scout s SET ability = GREATEST(1, LEAST(100, ROUND(CASE WHEN j.mean IS NULL THEN GREATEST(1, LEAST(100, s.experience::numeric)) ELSE 0.7 * GREATEST(1, LEAST(100, s.experience::numeric)) + 0.3 * GREATEST(1, LEAST(100, j.mean)) END))) FROM (SELECT sc.id, (SELECT AVG(e.value::numeric) FROM jsonb_each_text(sc.judgements::jsonb) AS e(key, value) WHERE e.value ~ '^-?[0-9]+(\.[0-9]+)?$') AS mean FROM scout sc) AS j WHERE j.id = s.id" --env=test

lando php bin/console dbal:run-sql "UPDATE scout SET ability = 1 WHERE ability IS NULL" --env=test
lando php bin/console dbal:run-sql "ALTER TABLE scout ALTER COLUMN ability SET NOT NULL" --env=test
lando php bin/console dbal:run-sql "ALTER TABLE scout ALTER ability SET DEFAULT 1" --env=test
```

**Nullable → backfill → `NOT NULL` → `SET DEFAULT` is the order that matters**, and it is the
third time this page has had to record the same lesson: a new column is two jobs, the migration
and this.

⚠️ **Do not skip the final `SET DEFAULT`.** `Scout::$ability` declares
`options: ['default' => 1]`, so a database without that default fails
`SchemaIsInSyncTest::testOrmMappingMatchesTheMigratedDatabase` with
`ALTER TABLE scout ALTER ability SET DEFAULT 1` outstanding. That test caught the same mistake in
the migration itself: it ended with `DROP DEFAULT`, copying `Version20261119190000`, whose column
declares **no** entity default — for that one the DB default IS the drift, and for this one
dropping it is. **The rule is "the DB must match the entity", not "always drop the default."**

🔴 **The general lesson, since this page now carries three instances of it.** A test database that
is not migration-tracked is a **standing trap, not an incident**. Every new column is two jobs,
and the second one has no reminder attached to it: nothing fails at write time, nothing fails in a
targeted test run, and the suite then fails in a shape that looks like a broken change rather than
a missing column.

**So after adding a column, check `information_schema.columns` in `wunderkind_test` BEFORE reading
a single assertion** — one command, and it removes a whole class of misattribution. Do it even
when the failures look like they have another cause, because as 2026-11-20 shows they may *mostly*
have another cause and still include this one.

⚠️ **And do not reason about the count from the precedents on this page.** The 2026-11-20 entry
was first written up here as "nineteen of the errors were the one missing column", by analogy with
2026-11-02's 35-of-36, before anyone compared the two runs' totals. It was **4 of 20**. The
numbers in a postmortem are only worth having if they come from the runs rather than from the
shape of the last incident; corrected the same day.

**`wunderkind_test` is shared across branches, and that is a trap when you switch.**
There is ONE test database for every branch, so it is only ever in sync with whichever
branch last synced it — and `master` can sit far behind `development`. Checking out the
other branch and running the suite then fails in bulk for reasons that have nothing to do
with your change, and the direction of the failure tells you which way the mismatch runs:

- DB **behind** the entities -> `Undefined column: ... does not exist` (e.g. on
  `development`, `location_config.big_share_percent`).
- DB **ahead** of the entities -> `Not null violation` on a column the older branch's
  entity never sets (e.g. on `master`, `club.name_normalised` — 137 errors, none real).

**Do not "fix" this by re-syncing the shared DB**: that just moves the breakage to the
other branch. `config/packages/doctrine.yaml` sets
`dbname_suffix: '_test%env(default::TEST_TOKEN)%'`, so a per-branch database is one env
var away and costs nothing:

```bash
# the wunderkind role cannot CREATE DATABASE - go through the postgres superuser
docker exec wunderkindbackend_database_1 \
  psql -U postgres -c 'CREATE DATABASE wunderkind_testmaster OWNER wunderkind;'
lando ssh -s appserver -c 'TEST_TOKEN=master php bin/console doctrine:schema:create --env=test'
lando ssh -s appserver -c 'TEST_TOKEN=master php vendor/bin/phpunit --no-coverage tests/...'
```

`TEST_TOKEN` must be set INSIDE the container (`lando ssh -s appserver -c '...'`); prefixing
`lando php` sets it on the host CLI, where Symfony never sees it. Verify with
`doctrine:schema:update --dump-sql --env=test`, which should report nothing to update —
that zero-drift baseline is what makes a failure meaningful instead of noise.

### Known test failures — the baseline to judge a run against (2026-11-16)

**`lando php bin/phpunit` is ~3,181 tests with FOUR failures, and all four are pre-existing.**
Recorded here because a green run is not the bar and someone who does not know that will go
hunting:

1. `NotificationLogCrudControllerTest` — empty content table.
2. `CompetitionControllerTest`, the narrative timeline — empty content table.
3. `FriendlyResolveSingleMatchTest` — empty content table.
4. `NpcClubForeignClubsCitySizeTest::testFindForeignClubsHandlesDefaultCitySizeFields` —
   **environmental, established rather than assumed**: it passes in isolation, fails inside the
   Repository suite, and still failed with every source change of the day reverted. It expects a
   persisted club inside `findForeignClubs(..., 10)` while the SHARED test database holds 14
   `npc_club` rows. Deliberately NOT "fixed" by deleting rows — cleaning the shared database
   only moves the breakage somewhere else.

   ⚠️ **AMENDED 2026-11-19: this is more unstable than "alternates by run composition" says, and
   the count in a run is 1 OR 2, OR ZERO.** Measured on a **stashed, clean tree** at `bac1228b`,
   consecutive IDENTICAL commands with nothing changing in between:

   ```
   CLEAN isolated run 1: Tests: 3, Assertions: 11, Failures: 1.
   CLEAN isolated run 2: Tests: 3, Assertions: 7,  Failures: 2.
   CLEAN isolated run 3: Tests: 3, Assertions: 7,  Failures: 2.
   CLEAN Repository suite run 1: Tests: 39, Assertions: 88, Failures: 2.
   CLEAN Repository suite run 2: OK, but there were issues! Tests: 39, Assertions: 96.
   CLEAN Repository suite run 3: Tests: 39, Assertions: 88, Failures: 2.
   ```

   So **`testFindForeignClubsReturnsCitySizeFields` joins and leaves the failure list by itself**,
   and the suite occasionally goes fully green. A full run therefore shows FOUR, FIVE or THREE
   failures without anything having changed. ⚠️ **Do not treat a move between 4 and 5 as a
   regression, and do not attribute it by assertion** — re-run it two or three times on the same
   tree before suspecting your change; that alone distinguishes this from a real break, and it is
   cheaper than the stash that was needed to establish it.

5. `NpcClubPlaceWeightingTest::testTheLargestTownOutdrawsTheSmallestInItsOwnBand` —
   **composition-dependent in the same way as #4, added 2026-11-19.** Seen as "70 is greater than
   72" on the merged `dev` tree (`gh run 44516901111`) and in **no** `development` run; isolated it
   passes 3/3, and a second full run on the identical tree had it pass while the other three still
   failed. The plausible trigger is the `npc_club` fixtures that landed with `LeagueSlotsAuthoringTest`
   and `LeagueSlotsCorrectionTest` — i.e. **probably ours**, unlike #4 — so it is listed rather than
   hunted. Do not treat its appearing or disappearing as a regression; re-run before suspecting a
   change, as with #4.

   It did **not** fire in the A7 full run of 2026-11-19 (3,294 tests, **3** failures: items #1, #2
   and #3 only), which is consistent with the range below rather than evidence it is fixed.

⚠️ **THE RANGE IS THEREFORE THREE TO FIVE**, because #4 and #5 each join and leave the list on their
own. **This file is the OWNER of that figure** — the app's `environment.md` explicitly defers to it,
and the range is about **FAILURES in the backend PHPUnit suite**, never about a test COUNT and never
about the app (`npm test`, where the baseline is ZERO on an idle machine). Three sessions in one day
judged `npm test` against this backend figure; see `docs/economy-loose-ends/agent-standing-notes.md`.
⚠️ **It is NOT "~31".** That figure is `app:economy:wage-table`'s own `31 check(s) failed`
output on the development clone — a gate result, not a test result — and it reached at least one
agent brief as a test baseline. Judging a suite run against 31 would silently absorb about 27 real
regressions.

⚠️ **Check a failure against this list before treating it as yours, and check it the way #4 was
checked** — stash the change and re-run the one test. Both are one command, and today a
template-and-CSS change was briefly suspected of breaking #1 for want of doing it.

**A FIFTH failure existed unlisted for days, and was FIXED 2026-11-19 — do not re-diagnose it.**
`WorldOverviewServiceTest::testMoneyIsConvertedToPoundsAndNullsSurvive` failed on a clean tree
("an unset tier must stay null, not become 0 / Failed asserting that 200000000 is null"), was
established by stash twice, and was never in this list. **It was not a production defect.**
`WorldOverviewService` resolves payouts through `LeaguePayoutResolver` by design, so a NULL column
INHERITS; the SE tier-2 fixture then resolved to £200,000,000 because `EconomyAdminTest` writes
base £100,000,000 with a tier-2 ratio of 2x into the **single shared `game_config` row** through
the real admin save and never restores it. The test now owns the payout config for its duration —
the same discipline it already applied to the live `CountryProfile` statuses — and passes.

⚠️ **`EconomyAdminTest` still does not restore `game_config`**, so the next test that depends on
an unauthored economy will meet the same trap. That is the general lesson of #4 and of this one:
**`game_config` and `starter_config` are single shared rows, and a test that depends on their
contents must own them for its duration rather than assume the shipped defaults.**

**A SIXTH FAILURE WAS RECORDED ON 2026-11-19 AND IS DELIBERATELY NOT ON THIS LIST, BECAUSE IT WAS
A DEFECT AND IT IS FIXED (A16, same day) — it was never a flake that stopped appearing.**
`LegacyNewGameFlowTest::testTheEconomyBlockShipsSwitchedOff` ("the turnover table must be
unauthored") failed because `EconomyDerivedSizeDriftTest` (then named
`EconomyAuthoredSizeDriftTest`, renamed by A23 the same day) and `EconomyTvCountryScalingGuardTest`,
both landed that day, wrote `38_866_153_952` into the shared `game_config` row in their own
`seed()` and never put it back. Both now stash the table in `setUp()` and restore it in
`tearDown()` — in `tearDown()` on purpose, because that runs after a FAILED assertion too, so the
row is clean exactly when something has already gone wrong. ⚠️ **If that assertion fails again it
is someone's pollution, not a known failure, and the something to find is whoever wrote the row.**

⚠️ **AND THE FULL RUN HAD BEEN HIDING IT.** Measured on 2026-11-19 before the fix: a single
`--filter`ed full run showed the usual THREE failures and that test PASSED, even though
`polluter -> reader` on its own failed every time. The reason is that
`tests/Controller/Admin/EconomyAdminTest` sorts between them and zeroes the turnover table
through the real admin save, so the full run was green by accident of two tests both abusing the
same shared row. A10 saw it fail because it ran the suite in 20-file batches, where that accident
does not hold. **So "it passes in a full run" is not evidence that a shared-row leak is harmless.**


### Test COUNTS through 2026-11-20 — and one unexplained gap that is still open

**Name the commit beside any count.** These are the full-suite collections measured on
`development` during the 2026-11-20 economy pass, newest last, each quoted from the session that
ran it:

| commit | collected/run | failures | errors | wall-clock | measured by |
|---|---|---|---|---|---|
| `6c5e4ad5` | **3,271** | 3 | — | — | A24 (`Assertions: 157033`) |
| `a818a60b` | **3,290** | 3 (same three, by name) | — | — | A25, twice |
| `a818a60b` | **3,359** | 3 | 1 | 292 s | country-scaling session, re-measured on a quiet machine |
| `c11c7f2d` | **3,366** | 3 | 1 | 301 s | country-scaling session |

Both of the last two were run alone, `--colors=never`, through Lando, serially, on the same test
database, with the machine confirmed quiet first. Raw output:
`docs/economy-loose-ends/evidence/country-scaling/suite-a818a60b.txt` and `suite-c11c7f2d.txt`.

🔴 **THE 3,290 AT `a818a60b` DOES NOT REPRODUCE, AND NOBODY HAS EXPLAINED THE 69-TEST GAP.** Two
runs by one session at that commit both returned 3,290 with the same three named failures. A later
run by another session at **that same commit** returned **3,359**. ⚠️ **Both figures claim the same
tree, so one of them is wrong and it is NOT YET KNOWN WHICH** — the candidate explanations on the
record are a different counting convention and a different checkout state when 3,290 was taken,
neither verified. **This is recorded rather than resolved on purpose: do not quietly pick one.**

**What to do until it is settled: re-measure the baseline yourself at the commit you are building
on, and quote your own figure.** That is exactly what surfaced the gap rather than absorbing it —
reconciling `c11c7f2d` against the pinned 3,290 would have shown a spurious **+76** instead of the
exact **3,359 + 7 = 3,366** that the change's own extract reconciles to (`CountryScalingTest` +6
new, `LeaguePayoutResolverTest` +2 net, `EconomyTvCountryScalingGuardTest` −1).

⚠️ **Contention moves TIMING, not a COUNT.** A25's two 3,290 runs agree with each other and A24's
3,271 reconciles against the then-baseline (3,265 + 6 = 3,271, + 19 = 3,290), so "the machine was
busy" is not available as an explanation for a count that does not reconcile. An unexplained count
means investigate; an unexplained *slow failure* may just be load.

⚠️ **A FINER EXCLUSION THAN THE ONE BELOW WORKS, AND IT IS WHY THESE COUNTS ARE HIGHER THAN 3,233.**
The section below filters out the whole of `AdminShellCoverageTest` (72 tests). Only **one test
case** actually OOMs — `::testThePageIsOnTheRedesignedShell"admin_logs"`, which `file()`s the whole
of `var/log/dev.log` (**4,108,724,354 bytes** on 2026-11-20) into a 3.828 GiB container and is
SIGKILLed at exit 137 about 189 tests in, reproduced three times. The two runs above used
`--exclude-filter admin_logs` and kept the other 71. ⚠️ **It is not a regression in anything and the
fix is log rotation, not a code change**; `var/log/` also holds a 2.0 GB `dev.log.2026-11-12.bak`.
Rotating it, and capping the read in `DashboardController::logs()`, are both open and the owner's
call.

**One caveat, and it bites more than once: `schema:create` builds structure, not content.**
Operator-authored content lives in tables the suite does not seed, so a fresh per-branch DB
fails every content-dependent test for that reason alone. Confirmed cases:

| Empty table | What fails, and how it reads |
|---|---|
| `game_event_template` | `MatchNarrativeGeneratorService` yields an empty timeline — `CompetitionControllerTest::testShowIncludesResultsOnceRoundsAreProcessed` ("non-empty narrative timeline") |
| `archetype` | `ArchetypeShowcaseServiceTest` — "actual size 0 matches expected size 4", plus a rotation test that cannot rotate one card |
| `starter_config` | `StartingGrantServiceTest` — an admin-edited range reads back as the entity default (`1`, not `123000000`) |

The tell is always a zero/default where content was expected, never a schema error. Copy the
content across rather than debugging it (add `--table=` per table):

```bash
docker exec wunderkindbackend_database_1 bash -c \
  "pg_dump -U postgres -d wunderkind_test --data-only \
     --table=game_event_template --table=archetype --table=starter_config \
   | psql -U postgres -d wunderkind_testmaster -q"
```

`master` has far fewer content-dependent tests than `development` (1148 vs 1576 in
`tests/Service`), so a fresh DB looking clean on one branch does not mean it will on the other.

#### Three columns synced on 2026-11-23

A full run on `development` opened with **1,606 errors and 22 failures**, and all but six were
`wunderkind_test` drift — three columns the entities had and the test DB did not:

```text
country_world_pack_cache.served_at
game_config.player_transfer_out_of_reach_tier_gap
game_config.player_transfer_wage_premium_per_tier
```

All three took a plain `doctrine:schema:update --force --env=test` (each has a SQL default, so
the "contains null values" abort did not apply). `MATCH_NARRATIVE` content was missing too — 87
rows in `wunderkind`, **0** in `wunderkind_test` — which empties every narrative timeline;
`lando php bin/console app:seed-match-narrative --env=test` restores it. That is the
content-not-structure trap below, and the narrative templates belong on its list.

**Syncing the test DB:**

1. `lando php bin/console doctrine:schema:update --dump-sql --env=test` — shows the real diff.
   Run this BEFORE trusting any full-suite result; a non-empty diff means you are about to
   debug someone else's drift.
2. `--force` aborts on any column needing a default for existing rows (`contains null values`),
   which is the common case, so it often cannot be used. Fall back to the one statement:
   `lando psql -d wunderkind_test -c "<ALTER …>"`.
3. **A column with a PHP-level entity default also needs its VALUE seeded** into existing rows.
   `schema:create` creates the column, not the content, so a row that predates the column stays
   empty and content assertions fail even once the schema matches — that was the
   `setup_tutorial_config` case, and filling the column with `{}` fixed the 500s while leaving
   four assertions failing until the entity's own default was written into the row.

The same applies to any FK/nullability change: `Version20261102140000` (competition_entrant) had
to be applied to `wunderkind_test` by hand before its regression test could pass.

### A full run is OOM-killed by `AdminShellCoverageTest` — **FIXED 2026-11-23, no filter needed**

✅ **`DashboardController::logs()` no longer reads the whole file.** It now tails the last
`$lines` lines backwards in 8 KiB chunks (`DashboardController::tailLines()`), so memory is
proportional to the lines asked for, not to file size, and a plain
`lando php vendor/bin/phpunit` completes all 3,399 tests with `var/log/dev.log` still at 4.0 GB.
**The exclusions below are history — do not pass them.**

⚠️ **Log rotation is still unaddressed and is still the owner's call**: `var/log/dev.log`
is 4.0 GB and `var/log/dev.log.2026-11-12.bak` another 2.0 GB. Nothing fails because of them
now; they are just 6 GB of disk.

The write-up below is kept for the diagnosis, which was correct, and because the
`--exclude-filter` shape is worth recognising if a page ever reads a whole file again.

### (HISTORY) A full run is OOM-killed by `AdminShellCoverageTest` — filter it out (2026-11-19)

**A plain `lando php vendor/bin/phpunit` dies at roughly 183/3,305 with exit 137** (the container
is OOM-killed, `docker inspect ... OOMKilled: true`, 3.83 GiB VM). ⚠️ **It is an ENVIRONMENT
result, not a failure to attribute**, it happens identically on a clean tree, and the cause was
narrowed twice to `AdminShellCoverageTest` alone (72 tests). It cost two sessions a full-suite run
on 2026-11-19 before it was written down.

```bash
# a clean full run of 3,233 tests — everything except AdminShellCoverageTest
lando php vendor/bin/phpunit --filter '/^(?!.*AdminShellCoverage).*$/'
```

⚠️ **A NARROWER EXCLUSION IS AVAILABLE AND KEEPS 71 OF THE 72 TESTS** — only the `admin_logs`
case OOMs. See "Test COUNTS through 2026-11-20" above, which is also where the suite's actual
collections are recorded, and **always pass `--colors=never` when you intend to read the summary
numbers**: PHPUnit colourises those fields.

Run that file on its own if you need it covered.


## Deployed dev/prod — Docker Compose

- `docker-compose.dev.yml` and `docker-compose.prod.yml` are structurally
  identical: both run the app container built from the root `Dockerfile`,
  image `ghcr.io/cadesile/wunderkind-backend:{dev,prod}`
- **Four public hostnames, all proxying to the same container, no path rewriting**
  (`deploy/proxy/Caddyfile`): prod serves `buildmyclub.co.uk`, `www.buildmyclub.co.uk`
  and **`api.buildmyclub.co.uk`**; dev serves `dev.buildmyclub.co.uk` and
  **`api.dev.buildmyclub.co.uk`**. The `api.` hosts are the intended client entry
  points, but because nothing rewrites the path **the `/api` prefix is still
  required there** — `api.buildmyclub.co.uk/api/telemetry` works,
  `api.buildmyclub.co.uk/telemetry` 404s. Worth stating because the hostnames were
  previously recorded nowhere in `.context/` (only the Caddyfile and a comment in
  `docker-compose.dev.yml`), which is an easy way to document the wrong base URL in a
  client contract.
- TLS and host ports 80/443 are owned by a shared Caddy proxy
  (`deploy/proxy/`), reached over an external `web` Docker network — these
  stacks themselves bind no host ports
- Dev stack runs the same optimised prod container as production
  (`APP_ENV: prod` even in the dev compose file — dev is a deployment tier
  name, not a Symfony env name)
- **PHP is pinned to an exact patch (`php:8.5.12-fpm-alpine`), not a floating
  `8.5` tag** — a rebuild can't silently land on a different PHP than the one
  the image was verified against, and the deployed version is auditable from
  the `Dockerfile` alone. Bump it deliberately on each PHP security release
  (8.5.12 carries the 2026-10-21 GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp
  fixes). The build pulls the base fresh every time — `docker/build-push-action`
  on an ephemeral GitHub runner has no local base-image cache — so the pin is
  for reproducibility and audit, not to defeat caching.
- **`opcache` must not appear in the `Dockerfile`'s `docker-php-ext-install` list.**
  PHP 8.5 compiles Zend OPcache in statically, so installing it as an extension builds
  no module and fails the whole build (`cp: can't stat 'modules/*'`). It is active
  anyway — `opcache.enable` defaults to On for FPM, `opcache.enable_cli` to Off.
- **Version-disclosure headers are suppressed in the deployed container** —
  `expose_php = Off` (`docker/php.ini`) kills `X-Powered-By: PHP/<exact patch>`, and
  `server_tokens off` (`docker/nginx.conf`) reduces `Server: nginx/<version>` to a bare
  `nginx`. Both previously published the exact build on every response, which makes
  matching the next advisory a lookup rather than a probe. `server_tokens off` is the
  only knob core nginx offers; removing the header outright needs the third-party
  headers-more module. Caddy fronts these stacks but passes the upstream `Server`
  header through, so fixing it at nginx is what actually changes the public response.
  **This does not apply to local Lando**, which serves via its own bitnami nginx and
  a `devwithlando` PHP image — neither reads these files, so local responses still
  carry versions.
- Postgres runs as a sibling container (`postgres-dev`/equivalent),
  deliberately kept off the `web` network so it's unreachable from the
  proxy or the other stack
- Required env vars (names only, from `.env` / compose files — never
  values): `APP_ENV`, `APP_SECRET`, `APP_URL`, `DEFAULT_URI`,
  `TRUSTED_PROXIES`, `DATABASE_URL`, `CORS_ALLOW_ORIGIN`, `JWT_SECRET_KEY`,
  `JWT_PUBLIC_KEY`, `CLUB_STARTING_BALANCE`, `MAILER_DSN`, `MAILER_FROM`,
  `MAILER_FROM_NAME`, `BETA_INVITE_MAILER_FROM`,
  `SOCIAL_TOKEN_ENCRYPTION_KEY`, `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`,
  `FACEBOOK_REDIRECT_URI`, `TWITTER_CLIENT_ID`, `TWITTER_CLIENT_SECRET`,
  `TWITTER_REDIRECT_URI`
