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
| Everything (router + `.context/stages/*/output/*.md`, all stages) | 20526 |
| Router only (`.context/CONTEXT.md`) | 451 |
| `01_overview` (router + its `output/`) | 1723 |
| `02_architecture` (same shape) | 2832 |
| `03_data` (same shape) | 7033 |
| `04_interfaces` (same shape) | 6343 |
| `05_ui` (same shape) | 1961 |
| `06_documentation` (same shape) | 1161 |
| `07_synthesis` (same shape) | 2180 |

**Typical saving vs. loading everything:** 81% (average across all 7
stages, all of which now have output)

**Last updated:** 2026-09-27 (added User owner identity — all 7 stage rows
re-measured precisely with `wc -c`; the prior "Router only: 51" figure was
stale/wrong, corrected here to the actual `.context/CONTEXT.md` size). Not
yet re-measured against the 2026-09-27 merge of `development` into
`competition` (Facility Manager/DOF/scout staff config, owner identity,
NpcClub kit+badge identity) — these figures will drift low again until the
next precise pass.
