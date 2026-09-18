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
| Everything (`.context/stages/*/output/*.md`, all stages) | 17996 |
| Router only (`.context/CONTEXT.md`) | 51 |
| `01_overview` (router + its `CONTEXT.md` + its `output/`) | 1649 |
| `02_architecture` (same shape) | 2928 |
| `03_data` (same shape) | 6638 |
| `04_interfaces` (same shape) | 6285 |
| `05_ui` (same shape) | 1906 |
| `06_documentation` (same shape) | 1112 |
| `07_synthesis` (same shape) | 2127 |

**Typical saving vs. loading everything:** 82% (average across all 7
stages, all of which now have output)

**Last updated:** 2026-09-18 (after stage 07_synthesis)
