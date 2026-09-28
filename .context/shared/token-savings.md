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
| Everything (router + `.context/stages/*/output/*.md`, all stages) | 31528 |
| Router only (`.context/CONTEXT.md`) | 484 |
| `01_overview` (router + its `output/`) | 1756 |
| `02_architecture` (same shape) | 3502 |
| `03_data` (same shape) | 10911 |
| `04_interfaces` (same shape) | 12381 |
| `05_ui` (same shape) | 1994 |
| `06_documentation` (same shape) | 1470 |
| `07_synthesis` (same shape) | 2416 |

**Typical saving vs. loading everything:** 84% (average across all 7
stages, all of which now have output)

**Last updated:** 2026-09-28 (ledger-amount financial-correctness fix pass
— all 7 stage rows re-measured precisely with `wc -c`, since the prior
2026-09-27 figures were already flagged as drifting low against the
2026-09-27 `competition` merge and had not been touched since). `03_data`
and `04_interfaces` grew the most this pass — not primarily from this
pass's own edits, but because `.context/` was already ~28 commits behind
`HEAD` going into this session (see `last-sync.md`), including two admin
club-profile commits and the landing-page Boardroom Incident Feed that
`04_interfaces/output/controllers.md` still doesn't document. These sizes
will drift again until that gap is reviewed and closed — see `last-sync.md`
for what's still outstanding.
