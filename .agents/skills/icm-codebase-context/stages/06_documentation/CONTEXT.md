# Stage 06: Documentation — Index Existing Docs

## Inputs

| Source | What to look for |
|---|---|
| `.context/stages/01_overview/output/tribal-knowledge.md` | Which existing docs the human already said are canonical (questionnaire question 5) |
| Every markdown file in the target repo (excluding vendored/generated dirs from `.context/shared/stack.md`) | Existing human-written documentation |

## Process

1. Walk the repo for markdown files, excluding the vendored/generated
   directories recorded in `.context/shared/stack.md` and anything under
   `.context/` or `.agents/` themselves.
2. For each file found, note its path and a one-line summary of what it
   covers — don't reproduce its content, this is an index, not a copy.
3. If the human already named canonical docs (tribal-knowledge.md), mark
   those explicitly as canonical in the index rather than treating them the
   same as everything else.

## Audit

Before writing `.context/stages/06_documentation/output/index.md`:

- [ ] Every path listed actually exists and is a markdown file you opened
- [ ] No vendored/generated directory content was included
- [ ] One-line summaries are accurate to the file's actual content, not guessed from the filename alone

## Outputs

| File | Content |
|---|---|
| `.context/stages/06_documentation/output/index.md` | Path + one-line summary for every relevant markdown file in the repo |
