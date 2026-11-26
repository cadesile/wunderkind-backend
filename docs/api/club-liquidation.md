# Game over: club liquidation and owned plots

Courtney, 2026-11-26. Plots belong to the account (`plot_claim.user`), not the club.

## Game over (decided on the device)

The app ends a save when, in the same week:

1. the club balance is below zero,
2. the owner can no longer invest (this season's regular and rescue season investments are used up, or the owner's account credit is zero), and
3. fan morale is below `gameOverFanMoraleThreshold` (game config, default 30).

All three are device state, so the server does not re-check them (all financial calculation happens on the device).

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

Dormancy reclaim only reads claims with a club, so an empty owned plot is never reclaimed for inactivity.

`GET /api/clubs/all` adds `liquidatedAt` and `deleteAfter` to each club.
