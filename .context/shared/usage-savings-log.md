# Usage Savings Log

> Tracks estimated tokens saved in real usage — each time an acting agent
> completes an implementation task by consulting `.context/` stage output
> instead of exploring raw source for equivalent information. Distinct
> from `token-savings.md`, which is a static snapshot of load-scope cost
> recomputed from current file sizes; this file is a running history of
> actual task-level savings. Same estimate caveat applies: chars÷4
> heuristic, directional not exact. Updated by the acting agent whenever
> it finishes such a task (see `SKILL.md`'s Triggers table).

## Running total

- **Tasks logged:** 1
- **Cumulative estimated tokens saved:** 16090

Both numbers only ever increment, on every new entry — never recomputed
from the table below, so the total survives old rows rolling off.

## Recent tasks (most recent 20 kept)

| Date | Stage(s) consulted | Task | Est. tokens saved |
|---|---|---|---|
| 2026-09-19 | 05_ui | Admin UI for viewing an Active Competition (bracket/rounds/results) — read design-system.md instead of grepping admin-theme.css/templates for the retro theme conventions | 16090 |

**Computing a task's estimate:** from `token-savings.md`, take the
"everything" total minus the combined scoped-load figure(s) for whichever
stage(s) you actually consulted this task — reuse those numbers directly,
don't re-measure from scratch. Consulting more than one stage in a task
means subtracting their *combined* scoped cost from "everything" once,
not summing each stage's individual saving separately.

**When adding a new row:** increment both numbers in "Running total"
first, then append the row. If the table now has more than 20 rows, drop
the oldest row(s) down to that cap — its value is already folded into the
running total above, so removing it loses no data, only the per-task
detail.
