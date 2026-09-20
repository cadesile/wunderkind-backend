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
| Everything (router + `.context/stages/*/output/*.md`, all stages) | 22448 |
| Router only (`.context/CONTEXT.md`) | 484 |
| `01_overview` (router + its `output/`) | 1649 |
| `02_architecture` (same shape) | 3112 |
| `03_data` (same shape) | 7607 |
| `04_interfaces` (same shape) | 8772 |
| `05_ui` (same shape) | 1906 |
| `06_documentation` (same shape) | 1392 |
| `07_synthesis` (same shape) | 2315 |

**Typical saving vs. loading everything:** 83% (average across all 7
stages, all of which now have output)

**Last updated:** 2026-09-20 (04_interfaces grew by
`push-notifications.md`, moved in from `docs/api/push-notifications.md`
by request — that file no longer exists; 06_documentation/07_synthesis
updated to repoint their references to the new location; same-session
follow-up pass added notification-observability content to `03_data`
(entities/schema/migrations) and `04_interfaces`
(routes/controllers/services) — small incremental growth on both, not
re-measured precisely; other stages unchanged this pass)
