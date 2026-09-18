# Stage 01: Overview — Stack & Environment

## Inputs

| Source | What to look for |
|---|---|
| Root manifests | `composer.json`, `package.json`, `go.mod`, `Gemfile`, `requirements.txt`, `pyproject.toml`, `Cargo.toml` |
| Subdirectories | `backend/`, `api/`, `server/`, `app/`, `web/`, `application/` — same manifests, one level down |
| Dev environment | `.lando.yml`, `docker-compose.yml`, `.devcontainer/`, `Makefile` |
| Existing instructions | `CLAUDE.md`, `AGENTS.md`, `README.md` — may state the stack explicitly |
| `.env.example` | masked env var names only, never values |
| `references/stack-signals.md` (this stage) | additional signals for non-standard project shapes (themes, monorepos) that a bare manifest scan misses |

## Process

1. Use your own Read/Glob/Grep tools to open every manifest file found under
   Inputs — read them in full, not truncated snippets. Do not shell out to
   a subprocess or another AI CLI to do this.
2. For each manifest found, note: language, framework (only if actually
   named in dependencies — not merely inferred from the manifest's
   existence), and whether it looks like the primary application or a
   secondary/vendored/generated concern. A near-empty manifest (e.g. a
   `composer.json` with no real `require` entries beyond one unrelated
   internal git dependency) is a signal to look harder, not a conclusion.
3. If more than one manifest points to a real stack — or any manifest looks
   thin — read `README.md`, `references/stack-signals.md`, and any
   `CLAUDE.md`/`AGENTS.md` in full before deciding. Look at the actual
   directory shape too: `sections/`, `templates/`, `snippets/`, `.liquid`
   files, and a `settings_schema.json` mean a Shopify theme even if a
   `composer.json` is sitting at the root; a rich, framework-specific
   `package.json` usually outweighs a thin `composer.json` or vice versa.
4. Record dev environment and database hints from
   `.lando.yml`/`docker-compose.yml`/`.devcontainer/`.
5. Draft `.context/shared/stack.md` (copied from this skill's
   `shared/stack.md` template) with every `{{PLACEHOLDER}}` filled in,
   including your reasoning.

## Checkpoints

| After Step | You Present | Human Decides |
|---|---|---|
| Step 4, before writing anything | Detected primary language, framework, app directory, dev environment, and your reasoning — including which manifests you found and which you discounted, and why | Confirm as-is, or correct any part of it |

**Do not skip this checkpoint, even when detection looks unambiguous.** This
is the deliberate fix for a known failure mode: a near-empty `composer.json`
previously caused a Shopify Liquid theme project to be misdetected as "php",
because a prior tool's heuristic short-circuited on manifest *presence*
before ever inspecting its content or asking a human. File presence alone is
never sufficient evidence — always show your reasoning and let the human
catch what you got wrong before stage 02+ builds on it.

## Audit

Before writing `.context/shared/stack.md` or
`.context/stages/01_overview/output/environment.md`:

- [ ] No `{{PLACEHOLDER}}` tokens remain in either file
- [ ] The value you're writing matches what the human confirmed at the checkpoint (not your original pre-checkpoint guess, if they corrected it)
- [ ] The app directory path you recorded actually exists — you opened at least one real file inside it
- [ ] The dev-environment section states only what a config file actually says, nothing inferred or guessed

If any check fails, revise and re-run the audit before writing — don't write
first and fix later.

## Outputs

| File | Content |
|---|---|
| `.context/shared/stack.md` | Canonical stack facts — the only home for this; every other stage references it, none restates it |
| `.context/stages/01_overview/output/environment.md` | Dev environment, database(s), required tooling |
| `.context/stages/01_overview/output/tribal-knowledge.md` | Only if `setup/questionnaire.md` was run this session — the human's answers to questions 4 and 5 |
