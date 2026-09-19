# Stage 07: Synthesis — Cross-Stage Overview

## Inputs

| Source | What to look for |
|---|---|
| `.context/shared/stack.md` + every other stage's `output/` | Everything produced so far — this stage doesn't explore the repo itself, it synthesizes what's already written |

## Process

1. Read every other stage's `output/` that exists (skip stages that
   haven't been run yet — don't invent content for them).
2. Write a short cross-stage overview: what this codebase is, in plain
   language, for someone who has read nothing else yet.
3. Note anything that looks architecturally significant across more than
   one stage (e.g. a data-model choice that shapes the API surface) — this
   is the one place cross-stage connections belong; individual stages stay
   scoped to their own topic per "one stage, one job."
4. Note current-focus signals if apparent from recent git activity
   (`.context/stages/02_architecture/output/git-activity.md`) — what part
   of the codebase seems to be under active work right now.

## Audit

Before writing `.context/stages/07_synthesis/output/*.md`:

- [ ] Nothing here restates a fact verbatim that already lives in another stage's output — link to it instead
- [ ] Every cross-stage connection claimed is backed by content actually present in the stages it connects
- [ ] Stages that haven't been run yet are noted as not-yet-available, not padded with guesses

## Outputs

| File | Content |
|---|---|
| `.context/stages/07_synthesis/output/overview.md` | Plain-language cross-stage summary |
| `.context/stages/07_synthesis/output/architecture-notes.md` | Cross-stage architectural connections worth flagging |
| `.context/stages/07_synthesis/output/current-focus.md` | What part of the codebase looks actively worked-on, if apparent |
