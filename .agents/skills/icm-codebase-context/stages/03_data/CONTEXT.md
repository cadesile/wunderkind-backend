# Stage 03: Data — Schema, Entities & State

## Inputs

| Source | What to look for |
|---|---|
| `.context/shared/stack.md` | Framework — determines where to look (e.g. Eloquent models vs. Prisma schema vs. Doctrine entities) |
| `.context/stages/02_architecture/output/structure.md` | Which module holds data-model code |
| Migration files, schema files, ORM model definitions | The actual, current shape of persisted data — not documentation about it, which may be stale |
| Existing `output/` in this stage, if present | Anything a human hand-edited last time — treat as authoritative, don't silently overwrite (see `references/revise-not-rewrite.md`) |

## Process

1. Read `.context/shared/stack.md` and `structure.md` to know where to look
   before searching blindly.
2. Read the actual migration/schema/model files directly — every entity,
   every field you document must trace back to a file you opened, not an
   inference from a name or a doc comment.
3. If `.context/stages/03_data/output/` already has content from a previous
   run, diff your fresh read against it: keep anything still accurate
   (including hand-added human notes), update anything the code has since
   changed, and never silently drop human-added content.
4. Note any in-memory/client-side state management (Redux, Zustand, Vuex,
   etc.) separately from persisted/database state — they answer different
   questions for a future reader.

## Audit

Before writing `.context/stages/03_data/output/*.md`:

- [ ] Every entity/table/field named traces to a file you actually opened this run
- [ ] Nothing carried over from a previous run without being re-verified against current code
- [ ] Hand-added human content from a previous run, if any, was preserved rather than deleted
- [ ] Genuinely unresolved questions (e.g. a field whose purpose isn't inferable from the code) are listed as open questions, not guessed at

## Outputs

| File | Content |
|---|---|
| `.context/stages/03_data/output/schema.md` | Tables/collections and their fields |
| `.context/stages/03_data/output/entities.md` | Domain entities and relationships |
| `.context/stages/03_data/output/state.md` | Client/in-memory state management, if any |
| `.context/stages/03_data/output/migrations.md` | Migration history relevant to the current schema |
