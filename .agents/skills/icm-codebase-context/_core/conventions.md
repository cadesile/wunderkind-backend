# ICM Conventions (vendored)

This file is a self-contained excerpt of the ICM (Interpretable Context
Methodology) conventions this skill actually uses, so the skill has no
runtime dependency on the reference repo
(github.com/RinDig/Interpretable-Context-Methodology).

## Five-layer routing

```
Layer 0: SKILL.md            -> "Where am I?"             (always loaded)
Layer 1: CONTEXT.md          -> "Where do I go?"           (read on entry)
Layer 2: Stage CONTEXT.md    -> "What do I do?"            (read per-task)
Layer 3: Reference material  -> "What rules apply?"        (loaded selectively)
Layer 4: Working artifacts    -> "What am I working with?"  (loaded selectively)
```

Read down the layers. Stop as soon as you have what you need — don't load
every stage's `output/` for every task.

## Every stage CONTEXT.md follows this shape

- **Inputs** — a table of what to read, and why.
- **Process** — numbered steps written for you (the acting agent) to
  execute yourself, using your own tools. Never a subprocess, never another
  AI CLI call — you explore the real repo directly.
- **Checkpoints** (when present) — a table of `| After Step | You Present | Human Decides |`.
  You pause, show your work/draft/finding, and wait for the human before
  continuing. Do not skip a checkpoint because a result looks obvious or
  unambiguous — checkpoints exist precisely for the cases that looked
  obvious and were wrong.
- **Audit** (when present) — a checklist you run yourself before writing to
  `output/`. Every item must be unambiguous pass/fail. If anything fails,
  revise before writing — don't write first and fix later.
- **Outputs** — a table of exactly which files you write, into
  `.context/stages/<this-stage>/output/` in the target repo (never inside
  the skill package itself).

## Other load-bearing patterns

- **CONTEXT.md = Routing, Not Content.** Router files (Layer 0/1) stay short
  and never contain the actual facts/rules — they point to where those live.
- **Canonical Sources.** One home per fact. `shared/stack.md` is the only
  home for stack/language/app-dir facts — every stage references it, no
  stage re-derives or restates it. `shared/last-sync.md` is the same idea
  applied to staleness: the one record of which commit `.context/` was last
  reviewed against. See `SKILL.md`'s Triggers table for the check itself —
  this file doesn't restate that logic, only points to it. `shared/token-savings.md`
  is the one record of the estimated token cost of loading everything vs.
  loading scoped-by-stage — an estimate (chars÷4 heuristic), not exact,
  updated whenever any stage's output changes (see `SKILL.md` step 5).
  `shared/usage-savings-log.md` is a different fact with its own home: not
  the structural cost of loading a stage, but a running record of tokens
  actually saved across real tasks that consulted `.context/` instead of
  exploring raw source — capped detail rows plus an ever-incrementing
  running total, updated on its own Triggers-table row, not step 5.
  Stage `01_overview` bootstraps it at zero if missing (so it's visible
  from the first run), but only that trigger ever adds real entries to it.
- **One-Way Cross-References.** If A points to B, B does not point back to A.
- **Every output is an edit surface.** A human can hand-edit any file under
  `.context/stages/*/output/` directly. The next time you touch that stage,
  treat the human's edit as authoritative — don't silently overwrite it.
- **Docs over outputs.** Don't learn "how to write output" by reading
  previous runs' output files — follow this stage's own Process instructions
  each time. Early outputs are often the least reliable ones.
