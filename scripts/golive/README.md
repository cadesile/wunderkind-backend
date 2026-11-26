# Go-live database migration

Moves production's players onto dev's world. Everything dev has built (config, economy,
countries, locations, plots, NPC clubs, enriched data, world pack buffers) is kept. From prod,
only accounts come over: users, owner ledgers, sign-in and device tokens, plus each club just
long enough for the existing buyout to value it, pay its owner and delete it. The buyout credit
is the player's reward for their old save; they start the new game and buy a plot with it.

Decisions behind this (Courtney, 2026-11-26): prod ledgers are kept as they are; the buyout
uses `clubsale-v1` as is; prod accounts win over dev test accounts; sync data and history are
not imported (the prod dump is the archive); the rehearsal runs on the dev server in a separate
database.

All scripts run **on the Hetzner box** from a checkout of this repo, as the deploy user. They
only write to two scratch databases inside `postgres-dev` (`wk_prod_stage`, `wk_golive`), and
refuse any other, except `promote.sh`, which is the cutover.

## Scripts, in order

| Step | Script | Writes to | What it does |
| --- | --- | --- | --- |
| 0 | `prod-counts.sh` | nothing (read-only transaction) | sizing counts and money totals, no personal data |
| 1 | `stage-prod.sh` | `wk_prod_stage` | dumps prod read-only, restores it, runs development's migrations on it |
| 2 | `make-golive.sh` | `wk_golive` | backs up dev, restores the backup as `wk_golive` |
| 3 | `import.sh` | `wk_golive` | `sql/purge-dev-users.sql`, `sql/export-accounts.sql`, `sql/import-accounts.sql`, `sql/verify-import.sql` |
| 4 | `sell.sh --dry-run`, then `sell.sh` | `wk_golive` | the buyout in batches of 25 (`app:clubs:sell-batch`), drains `clubsale`, then `sql/verify-sale.sql` |
| 5 | `promote.sh` | **production** | backs up prod, replaces it with `wk_golive` (refuses while the prod app is running) |

`sell.sh` and `promote.sh` ask for a typed word (`SELL`, `PROMOTE`) before they act.

## Rehearsal

Steps 0 to 4, as often as needed. Prod and the live dev site are only read. Then point a
new-game-save build at a one-off container on `wk_golive` and sign in with a prod account
(password: prod's JWT and social keys are not on dev) to check the avatar, the credit and
buying a plot.

## Cutover

1. Tag `pre-golive` on backend `master` and app `master`.
2. Freeze prod: `(cd /mnt/volume-wkf/wunderkind/prod && docker compose -f docker-compose.prod.yml stop app)`.
3. Run steps 1 to 4 again (fresh prod dump).
4. `promote.sh`.
5. Merge `development` into backend `master` and push. The prod deploy migrates (a no-op),
   seeds and starts the app.
6. Smoke test prod, then publish the app (OTA + store builds).

Merge only after step 4: the deploy runs migrations against whatever database prod has.

## Rollback

- Before `promote.sh`: start the prod app again. Nothing on prod changed.
- After it: restore the backup it printed, redeploy the `pre-golive` tag.
- After the app ships: fix forward. Correct a payout with a ledger reversal entry; a sale
  cannot be undone because the club is gone.

## Tested

The SQL and the wrappers were run end to end against Postgres 16 with both schemas built from
this repo's migrations (master's for prod, development's for dev) and seeded test data:
spoof owners left behind, an email on both sides (prod wins), ledger chains and avatars
unchanged, dev's claimed world pack set removed and its unclaimed buffer kept, plot claims
freed, sponsors unassigned, the refresh-token sequence moved on. The buyout itself
(`sell.sh`) needs the app image and was not run here; `verify-sale.sql` was checked against
a simulated buyout.
