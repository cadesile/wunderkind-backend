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
| Everything (router + `.context/stages/*/output/*.md`, all stages) | 29567 |
| Router only (`.context/CONTEXT.md`) | 483 |
| `01_overview` (router + its `output/`) | 1755 |
| `02_architecture` (same shape) | 3351 |
| `03_data` (same shape) | 9536 |
| `04_interfaces` (same shape) | 12023 |
| `05_ui` (same shape) | 1993 |
| `06_documentation` (same shape) | 1392 |
| `07_synthesis` (same shape) | 2416 |

**Typical saving vs. loading everything:** 84% (average across all 7
stages, all of which now have output)

**Last updated:** 2026-09-26 (merged `sprites` into `dev` — avatar/kit
generation demographic rules on top of this session's earlier NpcClub
kit+badge identity restructure and the competition round lifecycle
draw/resolve decoupling; all 7 stage rows re-measured precisely with
`wc -c` against the merged tree, reconciling the two branches' divergent
figures)
