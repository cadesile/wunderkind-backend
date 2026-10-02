# Token Savings Estimate

> Estimated, not exact — a chars÷4 heuristic (roughly 1 token per 4
> characters), not a real tokenizer. Treat these numbers as directional:
> good for seeing the shape of the saving, not for precise budgeting.
> Updated by the acting agent every time any stage's `output/` is written
> (see `SKILL.md`'s "How to use this skill", step 5). Measure file sizes
> yourself with your own tools (e.g. `wc -c`) — no bundled script computes
> this for you, consistent with the rest of this skill.

| Load scope | Est. tokens |
|---|---|
| Everything (router + `.context/stages/*/output/*.md`, all stages) | 32970 |
| Router only (`.context/CONTEXT.md`) | 484 |
| `01_overview` (router + its `output/`) | 1835 |
| `02_architecture` (same shape) | 3502 |
| `03_data` (same shape) | 11972 |
| `04_interfaces` (same shape) | 12683 |
| `05_ui` (same shape) | 1994 |
| `06_documentation` (same shape) | 1470 |
| `07_synthesis` (same shape) | 2416 |

**Typical saving vs. loading everything:** ~84% (not recomputed this pass —
only `03_data`/`04_interfaces` rows below were re-measured; the other 5
stage rows are carried over from 2026-09-28 and may be marginally stale)

**Last updated:** 2026-10-02 (targeted pass, not a full regen — added
`UserLedger`: new entity, `user_ledger` table/migration, `UserLedgerService`
(shared by the live sync path and the new `app:backfill-user-ledger`
command), and a read-only admin CRUD. `03_data`/`04_interfaces` rows
re-measured precisely with `wc -c`; `Everything` re-summed from all 7
stages' current `output/` sizes, not independently re-measured per stage.)
