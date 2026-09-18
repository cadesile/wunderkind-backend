# Setup questionnaire

Ask these conversationally, one at a time — don't dump the whole list on the
human at once. Replace every `{{PLACEHOLDER}}` you can answer from the
conversation (see `../_core/placeholder-syntax.md`); verify none remain
before telling the human setup is complete.

1. Where does the application source code actually live? (repo root, or a
   subdirectory like `backend/`, `api/`, `app/`) — `{{APP_DIR}}`
2. Are there any vendored, generated, or third-party directories that should
   never be treated as source of truth (a generated SDK client, a legacy
   folder being phased out, a near-empty manifest left over from tooling
   that isn't actually the primary stack)? — `{{EXCLUDED_DIRS}}`
3. Is there anything you'd expect automatic stack detection to get wrong —
   e.g. a manifest file that's present but isn't your primary stack? —
   `{{STACK_OVERRIDE}}`
4. Any tribal knowledge a new agent session should know up front — naming
   conventions, a gotcha, a "don't touch X" rule, a business rule that isn't
   written down anywhere in code? — `{{TRIBAL_KNOWLEDGE}}`
5. Which markdown docs already in this repo (if any) are already canonical
   and should just be indexed by stage `06_documentation`, not regenerated
   or duplicated? — `{{EXISTING_DOCS}}`

When done:
- Write answers 1, 3, and your reasoning into `../shared/stack.md`
  (`{{PRIMARY_LANGUAGE}}`, `{{FRAMEWORK}}`, `{{APP_DIR}}`,
  `{{EXCLUDED_DIRS}}`, `{{DETECTION_REASONING}}` — fill in `{{DEV_ENVIRONMENT}}`
  and `{{DATABASES}}` from your own exploration, per stage `01_overview`).
- Write answers 4 and 5 into
  `.context/stages/01_overview/output/tribal-knowledge.md` in the target repo.
- Confirm no `{{PLACEHOLDER}}` remains in any file you wrote before telling
  the human setup is complete.
