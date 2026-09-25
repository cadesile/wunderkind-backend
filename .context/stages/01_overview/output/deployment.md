# Deployment & Git Workflow

Full infrastructure runbook (topology, secrets, per-branch CI/CD wiring, one-time
server setup, verifying a deploy): `docs/deploy/hetzner.md` — not restated here.
This file covers the **git branching/merge policy** that decides what lands on
`dev` and `master` in the first place, which `hetzner.md` doesn't document.

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
- **`development`** — branched from `master` (not from `dev`). This is the
  integration branch for active work headed to both `dev` and `master`.

## Rules

1. Branch new work from `development`, after confirming it's up to date with
   `origin/development`. Do not branch from `dev`.
2. Land finished work on `development` first. From there, propagate to `dev`
   and `master` as two **separate** merges.
3. Use a real `git merge` to move changes between `development`, `dev`, and
   `master` — never `git cherry-pick` as the standard propagation method.
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
