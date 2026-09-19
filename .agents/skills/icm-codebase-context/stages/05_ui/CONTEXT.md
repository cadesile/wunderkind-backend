# Stage 05: UI — Design System & Component Style Guide

## Inputs

| Source | What to look for |
|---|---|
| `.context/shared/stack.md` | Framework — determines where a UI/theme layer would even live (React Native, React, Vue, a CSS framework, etc.) |
| `.context/stages/02_architecture/output/structure.md` | Which module holds UI/component code |
| Theme/token source file(s) (e.g. a `theme.ts`/`theme.js`, Tailwind config, styled-components theme, CSS custom properties, design-token JSON) | The actual, current color/typography/spacing values — not a doc claiming what they are |
| Shared/reusable component source files (e.g. `Button`, `Card`, `Input`, `Badge`) | Real styling rules as implemented, not as described in comments |
| Existing `output/` in this stage, if present | See `../03_data/references/revise-not-rewrite.md` — same rule applies here |

## Process

1. Read `.context/shared/stack.md` and `structure.md` first. If neither
   points to any UI/component layer (e.g. this is a backend-only API or
   CLI), skip to step 5 and record that finding — don't guess at a design
   system that doesn't exist.
2. Locate the actual theme/token source file(s) and read them in full.
   Extract color tokens, typography (font families/sizes/line-heights),
   and spacing/border-radius/elevation scales — every value must trace to
   a file you opened this run, never inferred from a component's visual
   appearance alone.
3. Identify the 5-10 most-reused shared UI components (start from what
   `structure.md` flags as the shared/UI module) and document each one's
   concrete styling rules: which tokens it uses, its variants, and any
   state-dependent styling (pressed/active/disabled).
4. Note explicit do's and don'ts only where the code itself demonstrates a
   consistent rule (e.g. "every border uses the same token, with no
   exceptions found") — don't invent stylistic guidance the code doesn't
   actually enforce.
5. If `.context/stages/05_ui/output/` already has content from a previous
   run, apply the revise-not-rewrite rule from stage 03.

## Checkpoints

| After Step | You Present | Human Decides |
|---|---|---|
| Step 2 (or step 1, if no UI layer was found), before writing anything | Whether a UI/design-system layer exists at all, and if so, which file(s) you're treating as the canonical token source and why | Confirm as-is, or correct any part of it |

**Do not skip this checkpoint.** Misdetecting or missing the canonical
theme source produces a style guide a future agent will trust when
generating UI — the same misdetection risk `01_overview`'s checkpoint
exists to catch for stack detection.

## Audit

Before writing `.context/stages/05_ui/output/design-system.md`:

- [ ] Every token/value documented traces to a file you actually opened this run
- [ ] If no UI layer was found, that finding is stated plainly, with reasoning — not left implicit by an empty file
- [ ] Hand-added human content from a previous run, if any, was preserved
- [ ] No stylistic "rule" is claimed unless the code actually demonstrates it consistently

## Outputs

| File | Content |
|---|---|
| `.context/stages/05_ui/output/design-system.md` | Color/typography/spacing tokens, documented shared components, and observed do's/don'ts — or, for a repo with no UI layer, a short note stating that plainly and why |
