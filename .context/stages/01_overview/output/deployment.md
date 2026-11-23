# Deployment & Git Workflow

Full infrastructure runbook (topology, secrets, per-branch CI/CD wiring, one-time
server setup, verifying a deploy): `docs/deploy/hetzner.md` — not restated here.
This file covers the **git branching/merge policy** that decides what lands on
`dev` and `master` in the first place, which `hetzner.md` doesn't document.

## Capacity — read before answering "will this scale?"

The production box is **2 vCPU / 3.8 GB**, and it hosts *five* containers:
`wunderkind-prod`, `wunderkind-dev`, `postgres-prod`, `postgres-dev` and `caddy`.
So prod does not have 2 cores; it shares them with the dev stack. Steady-state
memory is ~456 MB, which means **CPU is the binding constraint, not RAM** — the
opposite of what the 3.8 GB figure suggests at a glance.

Two always-on things beyond `php-fpm`/`nginx`/`crond` live here:
`registration-consume` (Messenger worker — if it stops, registrations silently
never complete) and the baked crontab.

The crontab's full schedule stays in the `Dockerfile`'s own comment block and in
`docs/deploy/hetzner.md` → "Cron", not here. One thing worth knowing at this level:
several entries' crontab cadence is only a *tick granularity*, with the real cadence
configured by an admin — `post-community-stat-tick` (posting schedule) and
`leaderboards-close-periods` (per-definition weekly/monthly/quarterly award cadence).
Changing those crontab lines changes how promptly work is noticed, never how often it
happens.

**`pm.max_children` is sized to the box, not left at the image default**, which was
5 — five concurrent PHP requests for the entire API. `docker/www.conf` sets 12.
The rule is `≈ 6 × vCPU`; the per-box table, what each upgrade actually buys, and
the two things a resize does *not* fix are in **`docs/deploy/hetzner.md` →
"PHP-FPM sizing"**. Don't re-derive those numbers — they come from a measured
registration (~22 ms of JSON for a 1.4 MB payload), and the table records it.

**The decided plan when scale demands it: move the dev API and its Postgres off
this box first, then resize.** That roughly doubles prod's effective CPU for no new
spend, so it comes before paying for cores — and it means the "2 vCPU" figure above
is temporary. Re-measure after the split rather than carrying today's
registrations/sec forward.

## Three branches, not two

- **`master`** — live production. Pushing here deploys the production release
  (store build + `production-apk`/prod container). Only merge what's actually
  ready to ship.
- **`dev`** — the deployed dev/staging environment (`dev-apk` / `wunderkind-dev`
  container). Pushing here triggers a real deploy too. `dev` is allowed to carry
  work that hasn't reached `master` yet, and can be genuinely ahead of and
  divergent from `development`/`master` for extended periods — e.g. an
  in-progress feature merged into `dev` for testing long before it's
  release-ready. **Don't assume `dev` and `development` share a clean linear
  history** — merging `development` into `dev` may need real conflict
  resolution even when the same merge into `master` is clean.
- **`development`** — a direct child of `master` (not of `dev`). **This is the
  working branch for most tasks** — commit directly on `development` rather
  than via a feature branch/PR; it's also the integration branch active work
  propagates to `dev` and `master` from.

## The merge flow — two spokes, never a chain

`development` is the hub. Both deploy branches are fed **from it**, separately:

```
          ┌──────────────► dev      (staging deploy)
development
          └──────────────► master   (production deploy)

              dev ──✘──► master     never
```

**Never merge `dev` into `master`.** `dev` is allowed to carry unreleased,
in-progress work for testing — that is its whole purpose — so a `dev` → `master`
merge ships whatever happens to be sitting in staging, which is exactly the set
of commits nobody decided to release. The same argument blocks `dev` →
`development`: it would drag that unreleased work back into the hub and from
there into the next production merge.

So a change reaches production by being merged from `development`, and only from
`development`. If a fix exists *only* on `dev`, it does not get promoted — land it
on `development` first (cherry-pick is the one-off escape hatch here, per rule 3),
then merge down the two spokes as usual.

A corollary worth stating: the two merges are independent, not sequential stages
of one pipeline. Merging into `dev` is not a step on the way to `master` and
grants nothing — `master` still takes its own merge from `development`. It is
normal and expected for `dev` to be ahead of `master` indefinitely.

## Operator rollouts that are not deploys

Some work ships **inert** and is turned on afterwards by an operator, so "deployed" and "live"
are different states and the runbook is the bridge. The economy overhaul (2026-11-12) is the
current example — eight migrations landed with every factor at parity and the wage model on
`legacy`, so the deploy itself was a provable no-op.

**`docs/deploy/economy-rollout.md`** is its procedure.

⚠️ **THE STEP BELOW IS OBSOLETE AS OF v11 (2026-11-18), AND THAT IS THE POINT OF v11.**
Flipping `economyWageModel` — or re-authoring any figure it reads — **no longer needs a
`WORLD_PACK_VERSION` bump at all**, because a pack holds no wages. Nothing monetary is in a
pack: the device resolves every money figure from live config at initialisation. A wage, balance,
payout, land price or sponsor band can now be re-authored and reach a NEW SAVE with no bump and
no regeneration. (Existing saves are a separate question and always were: a contract already
signed is honoured until renewal.)

The original step, kept because it is the history that explains the constant's docblock: flipping
`economyWageModel` changes world-pack CONTENT without changing pack SHAPE, so
`WorldInitializationService::WORLD_PACK_VERSION` has to be bumped in the same pass — and that
is a code change shipping through a normal deploy, sequenced between two admin actions. The
cache has no TTL, so skipping it leaves every already-cached country serving pre-flip wages
indefinitely. `staleWorldPacks` on the admin dashboard is the completion signal.

**Since 2026-11-17 a `WORLD_PACK_VERSION` bump does more than mark packs stale: it makes every
existing `WorldPackSet` UNCLAIMABLE at once**, because the claim filters on the version. Until
each live country is rebuilt, every new game there falls back to generating its world at request
time — correct and complete, just slower for that player, and uncached. Two things were supposed
to make this self-healing rather than an outage: the hourly `app:worldpack:maintain` sweep
restocking every live country without an operator, and the fallback needing no pool (squads are
generated), so it cannot fail on supply. The figure to watch is **COUNTRIES BELOW TARGET** on the
Worldpack cache screen, not `staleWorldPacks`, which counts payload rows rather than servable
sets. Full mechanics: `07_synthesis/output/architecture-notes.md`'s "World pack sets".

**Live example: `WORLD_PACK_VERSION` went 12 → 13 (`3e1555c5`) and 13 → 14 (`a818a60b`) on
2026-11-20**, the second because staff ability moved onto a new authored table and a cached v13
pack would have kept the fix dead on every device it reached. Both bumps are ability-shaped census
content rather than money, so v11's "the last bump money will ever cause" still stands.

## 🔴 Two world-pack rebuild traps, both established 2026-11-20, both expensive

**Between them these silently invalidated roughly three hours of device testing**, because the
admin screen reported success while nothing was written and every save afterwards read a stale or
mixed pack. ⚠️ **A `MIXED` or `STALE` version indicator is a GATE, not a footnote — read it before
any number underneath it.**

### 1. "Rebuild the world pack cache" does not rebuild CLAIMED sets, and a tester has claimed them all

✅ **Largely closed 2026-11-22:** delete-country now calls `deleteAllForCountry()`, which empties
claimed sets too (sparing only a world a device is still loading), and finished worlds are cleared
an hour after loading. The regenerate path still spares claimed
sets (`deleteUnclaimed`), so what follows still applies to regenerate. The original account:

`admin_worldpack_delete_country` called **`deleteUnclaimed($country)`**. **Claimed sets are spared
by design** — a device may still be downloading its tiers — and that protection is correct. The
trap is that **every save a tester creates claims a set**, so after a day of testing every set in
the country is claimed, the rebuild **deletes nothing, reports success, and the stale packs
survive**. The page then reads e.g. `EN 2/2 STALE 8/8`: present, complete and stale, which looks
like a *failed* rebuild rather than a *skipped* one.

- **✅ `php bin/console app:worldpack:warm <CC>` builds INLINE and works.** It is the reliable
  route and worked first time after the v14 bump (EN to 2 ready sets of 8 tiers, SC to 2 of 4).
- The admin alternative is the **per-set delete with `force`**, which exists precisely for "a set
  claimed by an abandoned save", then top up to target. ⚠️ **Forcing breaks the world of whatever
  save claimed it**, so only do it to throwaway saves.
- **The general lesson, which is not about world packs:** when a destructive operation protects a
  class of data, check whether the thing you are trying to fix is **entirely inside the protected
  class**. A no-op that reports success is worse than a refusal.

### 2. On the dev stack the async world-pack build does not run at all

Established **by elimination**, after three theories were each disproved — orphan cache rows, a
claimed-set deadlock, and `crond` being down. ⚠️ **All three were wrong, and the answer was found
by running the inline command rather than by reasoning from symptoms.**

**What is TRUE and therefore not worth re-investigating:** the write path stamps
`WORLD_PACK_VERSION` correctly; `deficit()` is version-aware, so stale sets never block a top-up;
`crond` **is** running (`crond -f -l 8`, PID 103 under supervisord); and dispatch succeeds.
**And yet nothing is ever written.** So the defect is in the consumer or the handler on the dev
stack, not in the generation code. **Cause unknown.**

**Until it is fixed: warm from the console and never trust the admin top-up on dev.**

⚠️ **`supervisorctl` DOES NOT WORK ON THIS IMAGE, AND THAT IS BY CONSTRUCTION, NOT A FAULT.**
`docker/supervisord.conf` defines no `[unix_http_server]` and no `[supervisorctl]`, so there is no
control socket and `supervisorctl status` can only return "no such file". **Check processes with
`docker exec <container> ps aux`** — that is how `crond` was confirmed running after
`supervisorctl` had been read as evidence it was down.

## Rules

1. Do most work directly on `development`, after confirming it's up to date
   with `origin/development` first. Never commit directly to `master` or
   `dev` — those are only ever reached by merging `development` in.
2. When a task is complete: merge `development` → `dev` and push (external
   dev/staging testing), then merge `development` → `master` and push
   (production release) — two **separate** merges, in that order, both
   sourced from `development`. Never merge `dev` into `master`, and never
   merge `dev` back into `development` — see "The merge flow" above.
3. Use a real `git merge` to move changes out of `development` to `dev` and
   to `master` — never `git cherry-pick` as the standard propagation method.
   Cherry-pick is only acceptable as a one-off fix to transplant a specific
   commit onto a branch that doesn't share its ancestry.
4. Before merging into `master`, check for master-only commits first
   (`git log development..master`) — `master` can accumulate small direct
   fixes independently of `development`.
5. Verify on the **merged tree**, before every push, not before: typecheck
   clean and the full test suite green (`lando php vendor/bin/phpunit
   --no-coverage`). Treat any failure as a real regression to fix, not
   something to push past.
6. Treat pushes to `dev` and `master` as real deploys, not routine commits —
   `dev` triggers a real OTA/staging deploy, `master` triggers the production
   release. Only push when explicitly instructed to, and confirm before
   force-pushing or discarding history on either.
7. If `dev` and the merge target (`development` or `master`) have diverged in
   conflicting ways, resolve deliberately — read both sides, merge intent —
   rather than defaulting to `--ours`/`--theirs`.
