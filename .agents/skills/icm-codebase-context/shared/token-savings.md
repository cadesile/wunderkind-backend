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
| Everything (`.context/stages/*/output/*.md`, all stages) | {{TOTAL_TOKENS}} |
| Router only (`.context/CONTEXT.md`) | {{ROUTER_TOKENS}} |
| `01_overview` (router + its `CONTEXT.md` + its `output/`) | {{S01_TOKENS}} |
| `02_architecture` (same shape) | {{S02_TOKENS}} |
| `03_data` (same shape) | {{S03_TOKENS}} |
| `04_interfaces` (same shape) | {{S04_TOKENS}} |
| `05_ui` (same shape) | {{S05_TOKENS}} |
| `06_documentation` (same shape) | {{S06_TOKENS}} |
| `07_synthesis` (same shape) | {{S07_TOKENS}} |

**Typical saving vs. loading everything:** {{AVG_SAVINGS_PERCENT}}% (average
across stages with existing output; leave a stage's row blank if it hasn't
run yet rather than estimating zero or guessing)

**Last updated:** {{LAST_UPDATED_DATE}} (after stage {{LAST_UPDATED_STAGE}})
