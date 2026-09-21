---
name: icm-codebase-context
description: >
  Maintains this repo's .context/ knowledge base (ICM methodology) — a
  routed, stage-by-stage record of stack, architecture, data model,
  interfaces, docs, and synthesis. Use before exploring this codebase for
  ANY task — not just when the user explicitly asks an architecture-shaped
  question. Before spawning a sub-agent, grepping, or reading manifests/
  config/source files directly, check whether the relevant stage's
  output/ already has the answer. Also use when .context/ is empty or
  missing (first run), or when a change you're making would make an
  existing .context/ stage output stale.
---

# icm-codebase-context

You are the agent that builds and maintains this repo's `.context/`
knowledge base by following ICM (Interpretable Context Methodology):
routed, stage-by-stage markdown instead of one monolithic file, so a future
agent (possibly you, in a later session) loads only what's relevant.

There is no generator script here and no AI subprocess to call — **you**
explore the repo with your own tools (Read/Glob/Grep/Bash) and write the
output yourself, following each stage's instructions.

`.context/` is the master, committed source of truth for this codebase.
Per-agent pointer files (`CLAUDE.md`, `AGENTS.md`, `GEMINI.md`, etc.) are
deliberately gitignored — thin, disposable pointers to `.context/`, not the
truth themselves. Don't be thrown if one is missing; see the first trigger
below.

## Before exploring anything, for any task

This applies unconditionally, not just when a task is phrased as an
architecture question. Before exploring this codebase for **any** task —
including spawning a sub-agent (e.g. an Explore agent), grepping, or
reading manifests/config/source files directly — read `.context/CONTEXT.md`
first and check whether the relevant stage's `output/` already has the
answer. A task like "check dependencies for security updates" needs facts
(PHP/language version, Docker build stages, CI config) that are exactly
the kind of thing `01_overview/output/environment.md` and
`02_architecture/output/structure.md` already document — don't re-derive
them from `Dockerfile`/`.gitlab-ci.yml`/manifests from scratch, and don't
delegate that re-derivation to a sub-agent either, just because the task
itself wasn't phrased as "what's the architecture." Only explore raw
source for what's missing from `.context/` or plausibly stale (see the
staleness check below).

## Triggers

| Situation | Action |
|---|---|
| Starting work in this repo, `.context/shared/last-sync.md` exists | Run `git log <recorded-commit>..HEAD --oneline -- . ':!.context' ':!.agents'` (the path exclusions stop the check's own housekeeping commits — e.g. one that only updated `last-sync.md` — from tripping it). If this isn't a git repo, or the recorded commit is missing/invalid, skip silently. If the command returns any commits, list them briefly and ask the human whether to review them and update the relevant stage(s) before continuing — don't proceed as if `.context/` were current without asking |
| `.context/shared/last-sync.md` doesn't exist yet | Nothing to compare against — skip the staleness check (expected before stage `01_overview` has ever completed) |
| No `CLAUDE.md`/`AGENTS.md`/`GEMINI.md` pointer exists, but `.context/` does | The pointer was gitignored and this is a fresh clone that hasn't installed one locally yet. Treat `.context/` as authoritative anyway — read `.context/CONTEXT.md` as normal — and recreate a local pointer file for whichever agent you are (run `npx create-icm-context .`, or write one by hand following the sentinel format any existing pointer uses) |
| `.context/stages/*/output/` all empty or missing | This is first run ("warming") — start at `stages/01_overview/CONTEXT.md` now, in this session |
| User says something like "warm", "warm the context", or `/icm-context warm` | Run every stage under `.context/stages/` whose `output/` is empty or missing, in order (`01_overview` → `07_synthesis`), respecting each stage's Checkpoints and Audits — same action as first-run warming above, just explicitly invoked instead of inferred. Leave stages that already have output alone (that's the "explicitly asks to regenerate/refresh" row below) |
| User asks "what stack/framework is this", "how is this structured", "where's the data model/API" — or any other task, however phrased, that needs facts a stage already documents (see "Before exploring anything" above) | Read `.context/CONTEXT.md`, jump straight to the relevant stage's `output/` — don't re-run a stage that already has output, and don't explore raw source or spawn a sub-agent for what's already answered there |
| You just changed schema, migrations, or persisted state | Update `.context/stages/03_data/output/*.md` before finishing the task |
| You just added/changed routes, controllers, services, or an external API surface | Update `.context/stages/04_interfaces/output/*.md` before finishing the task |
| You're about to write or modify UI/component code | Read `.context/stages/05_ui/output/design-system.md` first, before writing any UI code — don't invent colors, spacing, or component patterns the design system already defines |
| You just changed the design system or theme tokens (new/changed color, font, spacing, or shared component) | Update `.context/stages/05_ui/output/design-system.md` before finishing the task |
| You just changed top-level architecture (new service, moved a directory, changed the framework) | Update `.context/stages/02_architecture/output/*.md`, and `.context/shared/stack.md` if the stack itself changed |
| User explicitly asks to (re)generate or refresh context | Re-run the relevant stage(s) in order, respecting each stage's Checkpoints — don't skip them because a prior run exists |
| First time this skill is being installed into a repo | Run `setup/questionnaire.md` before stage `01_overview` |
| `.context/shared/` exists (stage `01_overview` has already run at some point) but `usage-savings-log.md` is missing | The skill was updated after this repo was last warmed, so stage `01_overview`'s bootstrap step never ran for this file. Copy it from this skill's `shared/usage-savings-log.md` template as-is — `{{TASKS_LOGGED_COUNT}}`/`{{CUMULATIVE_TOKENS_SAVED}}` at `0`, `{{MAX_ENTRIES}}` at `20`, empty table — right now, without re-running stage `01_overview` or any other stage |
| You just finished an implementation task that used `.context/` stage output instead of exploring raw source for equivalent information | Append an entry to `.context/shared/usage-savings-log.md` (bootstrapping it first per the row above, if it's somehow still missing) — date, stage(s) consulted, one-line task summary, and estimated tokens saved (computed per that file's own instructions, reusing `token-savings.md`'s existing numbers) — incrementing its running total and trimming to the row cap if needed |

## How to use this skill

1. Read `CONTEXT.md` (in this same folder) — it routes your current task to
   the right stage.
2. Read `_core/conventions.md` once, if you haven't already this session —
   it defines the shape every stage's `CONTEXT.md` follows and the
   load-bearing patterns (Checkpoints, Audits, Canonical Sources).
3. Open the stage(s) `CONTEXT.md` names and follow its Process yourself.
   Never shell out to another AI CLI or subprocess to do this — you already
   have the tools.
4. Respect every Checkpoint — pause and let the human confirm or correct
   before continuing. Respect every Audit — run the checklist before
   writing to `output/`, not after.
5. Write output only to `.context/stages/<stage>/output/` in the **target**
   repo (the one you're working in), never inside this skill folder itself.
   After writing any stage's output, also update two shared files:
   - `.context/shared/last-sync.md` — current commit (`git rev-parse HEAD`),
     today's date, and which stage(s) you just touched. This is what the
     staleness check in the Triggers table above reads.
   - `.context/shared/token-savings.md` — re-measure with your own tools
     (e.g. `wc -c` on the relevant files/dirs, ÷4 for an estimated token
     count) and update the row(s) for whichever stage(s) you just touched,
     the "everything" total, and the savings percentage. Don't recompute
     rows for stages you didn't touch this pass unless their numbers are
     actually stale.

   A third shared file, `.context/shared/usage-savings-log.md`, tracks a
   different kind of event — not output being written, but output being
   *used* to finish a real implementation task — and updates on the
   separate Triggers-table row above, not as part of this step.
