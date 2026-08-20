# docs/api/npc-club-city-size.md

> Title: API Spec — NPC Club City-Size Fields · 803 words · parsed 2026-08-20T22:28:10.476Z

## Outline
- Where this shows up
- New fields
- Example club object
- Backfill / pre-existing data
- Caching caveat — applies to the world pack only
- Client integration notes

## Summary
This spec documents four new fields (`region`, `citySize`, `populationSize`, `isCapital`) added to NPC club objects returned by `POST /api/initialize/league/{tier}`, `GET /api/club/foreign`, and `GET /api/scout/foreign-clubs`, describing the real-world city an NPC club is themed after. An agent should read it when implementing client-side handling of NPC club data — particularly to understand the backfill defaults for pre-existing clubs (`region: null`, `citySize: "MEDIUM"`, `populationSize: 0`, `isCapital: false`) and the caching caveat that world-pack club data may lag behind admin-side club regeneration (unlike the foreign/scouting endpoints, which are always fresh).
