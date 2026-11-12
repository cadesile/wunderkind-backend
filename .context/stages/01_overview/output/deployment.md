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

**`docs/deploy/economy-rollout.md`** is its procedure. The step worth knowing about from here:
flipping `economyWageModel` changes world-pack CONTENT without changing pack SHAPE, so
`WorldInitializationService::WORLD_PACK_VERSION` has to be bumped in the same pass — and that
is a code change shipping through a normal deploy, sequenced between two admin actions. The
cache has no TTL, so skipping it leaves every already-cached country serving pre-flip wages
indefinitely. `staleWorldPacks` on the admin dashboard is the completion signal.

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
