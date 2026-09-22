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
| Everything (router + `.context/stages/*/output/*.md`, all stages) | 27450 |
| Router only (`.context/CONTEXT.md`) | 483 |
| `01_overview` (router + its `output/`) | 1649 |
| `02_architecture` (same shape) | 3351 |
| `03_data` (same shape) | 9123 |
| `04_interfaces` (same shape) | 11601 |
| `05_ui` (same shape) | 1906 |
| `06_documentation` (same shape) | 1392 |
| `07_synthesis` (same shape) | 2315 |

**Typical saving vs. loading everything:** 84% (average across all 7
stages, all of which now have output)

**Last updated:** 2026-09-22 (competition round lifecycle draw/resolve
decoupling — `02_architecture`, `03_data`, `04_interfaces` rows
re-measured precisely with `wc -c` this pass, superseding the prior
"not re-measured precisely" drift on `03_data`/`04_interfaces` from the
2026-09-20 follow-up pass; `01_overview`/`05_ui`/`06_documentation`/
`07_synthesis` untouched this pass, figures carried forward unchanged)
