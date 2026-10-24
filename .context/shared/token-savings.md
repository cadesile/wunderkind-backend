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
| Everything (router + `.context/stages/*/output/*.md`, all stages) | 52193 |
| Router only (`.context/CONTEXT.md`) | 535 |
| `01_overview` (router + its `output/`) | 3779 |
| `02_architecture` (same shape) | 3888 |
| `03_data` (same shape) | 17477 |
| `04_interfaces` (same shape) | 21412 |
| `05_ui` (same shape) | 3863 |
| `06_documentation` (same shape) | 2027 |
| `07_synthesis` (same shape) | 2960 |

**Typical saving vs. loading everything:** ~85%

**Last updated:** 2026-10-24 (targeted pass — updated `03_data/output/entities.md`, `03_data/output/migrations.md`, and `04_interfaces/output/services.md` for snapshot `identity`/`stadium`/`appearance` normalization and `CompetitionResult::$computationAuditJson`; all stage rows re-measured with `wc -c`.)
