# GET /api/initialize/land

The land under every NPC club in a country, prices included. Added 2026-11-26 so the device can
store each NPC club's plot data with the club and value it offline (investor offers are priced
from club value).

Authenticated. Uncached. No starter precondition, so a save built before this endpoint existed
can backfill from it.

`?country=XX` is optional; without it the caller's club's country is used (404 when the caller
has no club).

```json
{
  "country": "IT",
  "clubs": [
    {
      "clubId": "01a203be-…",
      "locationId": "01a1…",
      "town": "Brindisi",
      "region": "Apulia",
      "plotId": "01a1…",
      "plotNumber": 1,
      "plotLandPence": 350000000,
      "townLandPence": 350000000,
      "population": 86000,
      "capital": false,
      "citySize": "MEDIUM",
      "enrichmentStatus": "enriched",
      "plots": 2,
      "npcClubs": 1,
      "npcOccupied": 1,
      "claimed": 0,
      "available": 1
    }
  ]
}
```

- Only clubs with a town are listed. A club in a placeholder town (no location row) is absent.
- `plotId`, `plotNumber` and `plotLandPence` are null for an unseated club: only the first
  `LocationCapacityService::NPC_PLOT_CAP` (2) clubs in a town sit on a plot.
- `plotLandPence` is the plot's override, else the town's price. Both prices are null until the
  town is valued.
- Capacity figures mean what they mean on `GET /api/locations/{id}` (`LocationCapacityService`).

## Why not the world pack

A pack is a census and carries no money (`WorldPackSnapshotBuilder`, `WorldPackCarriesNoMoneyTest`):
a price inside a cached pack makes every land re-authoring a `WORLD_PACK_VERSION` bump. This
endpoint follows the `/api/initialize/leagues` pattern instead: uncached, resolved live, stored by
the device.
