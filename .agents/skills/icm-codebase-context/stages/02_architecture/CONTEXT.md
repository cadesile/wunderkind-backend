# Stage 02: Architecture — Structure & Module Boundaries

## Inputs

| Source | What to look for |
|---|---|
| `.context/shared/stack.md` | App directory, excluded/vendored dirs — don't re-derive these |
| Directory tree under the app directory | Top-level module/package boundaries, naming conventions |
| Git history (`git log --stat`, recent commits) | Which areas of the tree actually change together and how often |
| Build/config files (`Makefile`, CI workflow files, `docker-compose.yml`) | How the pieces are actually wired together and deployed |

## Process

1. Read `.context/shared/stack.md` first — use its app directory and
   excluded-dirs list rather than re-scanning everything from scratch.
2. Walk the app directory (respecting the excluded dirs) and identify the
   real top-level module/package boundaries — not every folder, only the
   ones that represent a genuine architectural seam (e.g. "this is where
   all HTTP handling lives," not every leaf folder).
3. Use `git log` to see which files/directories change together frequently
   — this often reveals real coupling that the directory layout alone
   hides.
4. Note how pieces are wired together (a shared DB, a message queue, a
   monorepo boundary) from build/CI/compose config — state only what a
   config file actually shows, don't speculate about runtime behavior you
   haven't verified.

## Audit

Before writing `.context/stages/02_architecture/output/*.md`:

- [ ] Every module/boundary named actually exists as a real directory you opened
- [ ] Nothing restates a fact already in `.context/shared/stack.md` — link to it instead
- [ ] Git-activity claims are backed by an actual `git log` result, not assumption

## Outputs

| File | Content |
|---|---|
| `.context/stages/02_architecture/output/structure.md` | Module/package boundaries and what each is responsible for |
| `.context/stages/02_architecture/output/git-activity.md` | Which areas change together, recent hotspots |
