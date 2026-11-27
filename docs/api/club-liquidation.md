# Game over: club liquidation and owned plots

Courtney, 2026-11-26. Plots belong to the account (`plot_claim.user`), not the club.

## Game over (decided on the device)

Courtney, 2026-11-27: whether to invest is the player's choice. When the club balance is below zero and fan morale is below `gameOverFanMoraleThreshold` (game config, default 30), pressing Advance warns "if you advance, your club will be liquidated". Confirming ends the save and the app calls the endpoint below. The player can cancel and invest first. The server does not re-check (all financial calculation happens on the device).

## `POST /api/club/liquidate`

Requires `X-Club-Id`. No body. Idempotent.

```json
{
  "liquidated": true,
  "clubId": "…",
  "liquidatedAt": "2026-11-27T00:10:00+00:00",
  "deleteAfter": "2026-12-27T00:10:00+00:00",
  "detachedClaimIds": ["…"],
  "claims": [ /* GET /api/plots/claims/mine shape */ ]
}
```

What it does:

- sets `club.liquidated_at` and `club.delete_after` (now + `liquidatedClubDeleteAfterDays`, default 30);
- detaches the club from its plot claim. The claim stays active and the owner's, so the plot shows as `restartable: true`;
- clears the club's home location, freeing the town's name reservation.

No money moves. A replay keeps the first dates and detaches nothing.

## Restarting on an owned plot

`GET /api/plots/claims/mine` now returns `restartable` on each claim (true when no club stands on the plot). Only those plots are offered for a new club. Founding works as before: `ClubInitializationService` accepts an active, club-less claim at the given `locationId`. If the claim already `hasStadium`, `POST /api/plots/claims/{id}/stadium` returns it unchanged and charges nothing.

Selling back an empty plot pays land (90% of current value) + stadium (50% of what was paid); there is no club value because no club is attached.

## Deletion

`app:clubs:purge-liquidated` (daily 04:20 UTC, `docker/clubs-purge-liquidated.sh`, `--dry-run`, `--limit`) deletes clubs past `delete_after` through `ClubScrubService`. It detaches any claim first, so the owner's plot is never released by a purge.

## Inactivity sell-back (Courtney, 2026-11-27)

Every owned plot is sold back automatically (`app:plots:dormancy-reclaim`, sell-back price) once `plotDormancyReclaimDays` (game config, default 30, was a fixed 30) pass without a sync. The clock follows each plot's own club, so a player keeps at most as many plots alive as they have save slots (2).

- Plot with a club: the club's last sync. Inbox warnings 7 days and 1 day before, and the reclaim refuses an unwarned plot, as before.
- Empty plot (club liquidated, or bought and never used): `plot_claim.vacated_at`, else `claimed_at`. No club inbox exists, so no warning row is needed; the app shows the date instead. Founding a new club on it stops the clock.

`GET /api/plots/claims/mine` and the liquidation answer carry `reclaimAt` (the deadline) and `emptySince` on each claim.

`GET /api/clubs/all` adds `liquidatedAt` and `deleteAfter` to each club.
