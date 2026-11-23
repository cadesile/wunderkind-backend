# Current focus — what is open, and who owns it

> **This file is an INDEX of live work, not a changelog.** It names what is open, what blocks it,
> and the document that owns each thread. Shipped work belongs in git and in
> `.context/shared/last-sync.md`. **When something ships, delete its entry.** The previous contents
> (2026-11-02 → 2026-11-18, all shipped or superseded) are verbatim in
> `wunderkind-docs/context-archive/backend-07_synthesis-current-focus-history.md`.

- **Branch:** `development` (PR base), merged into `dev`, which deploys dev.buildmyclub.co.uk.
  `master` lags and is not the working base.
- **As of:** 2026-11-23, `5d162c1`.

## 🔴 1. Open questions for the owner

- **A retried world-pack tier is no longer coherent.** Since `df3cae1` a served tier row is deleted,
  so a retry after a lost response is built inline from a fresh generation. Accept, or keep the row
  until the device confirms? See `architecture-notes.md` "The claim".
- **The scout judgement band (`PoolConfig::$scoutJudgementBandBp`) is a placeholder at 10000**, by
  the owner's deferral until the tier scaling is verified on device. 12500 is the derived first
  alternative. The 70/30 `ability` weights (A32) are also unruled. Report:
  `wunderkind-docs/economy-loose-ends/a32-scout-ability-floor-report.md`.

## 🟡 2. The economy: correctness now, calibration later

Owner intent (2026-11-23): the economy should be hard. Clubs whose wages exceed income are fine for
now; fix correctness first, calibrate later (cut wages across the board if most clubs go insolvent,
then maybe raise income). Planned: owner investment into clubs, NPC included, capped per tier.
Everything economic scales by country (`EconomyScale::countryFactorBp`, now also published as
`economyTierBaseline.countryFactorBp`). Entry point for the workstream:
`wunderkind-docs/economy-loose-ends/START-HERE.md`.

## 🟡 3. Operational traps that are still live

- **On the dev stack the async world-pack build does not run**; `app:worldpack:warm <CC>` builds
  inline and works. `01_overview/output/deployment.md` trap 2.
- **Known test failures and flakes** (`NpcClubForeignClubsCitySizeTest` is non-deterministic, and
  `wunderkind_test` holds leaked `league` rows that make `LeaderboardHallOfFameTest` flaky): judge a
  run against the baseline in `01_overview/output/environment.md`, re-measured at your own commit.
