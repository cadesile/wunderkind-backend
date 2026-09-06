# Engineering Conventions

**Hand-written, not generator output.** Rules that constrain how services in this stage are
changed, and that cannot be inferred by reading a single file.

## Config values must round-trip through Import/Export

Three admin screens back up and restore domain data as JSON:

| Route | Service | Covers |
|---|---|---|
| `admin_config_content` | `ConfigImportExportService` | `GameConfig`, `StarterConfig`, `PoolConfig` |
| `admin_narrative_content` | `NarrativeImportExportService` | `GameEventTemplate`, `FacilityTemplate`, `PlayerArchetype`, `TacticalAdvantage` |
| `admin_world_content` | `LeagueImportExportService` | `League`, `NpcClub` |

**Any work that adds, renames or removes a persisted field on one of those entities is
incomplete until the corresponding service carries it in both directions.** This applies to
agent work as much as human work, and it is not optional cleanup: the failure is silent.
An import does not error on a field it doesn't recognise — it just doesn't apply it, so the
value comes back at its entity default and an operator restoring a backup loses settings
without being told.

This is exactly how the codebase drifted before 2026-09: 130 of 244 persisted config fields
had accumulated on the entities without ever reaching the exporter, along with `NpcClub`'s
city-size fields and `FacilityTemplate::$baseConstructionWeeks`.

### How each service is kept honest

- **Config is reflection-driven.** `ConfigImportExportService` walks every `#[ORM\Column]`
  property on the three singletons, resolves `get`/`is` and `set` accessors, and coerces
  values from the setter's own signature. New config fields are therefore covered with no
  code change. The only judgement left is the denylist.
- **`ConfigImportExportService::DENIED_PROPERTIES` is the deliberate-exclusion list.**
  `#[ORM\Id]` columns are skipped automatically and need no entry. Add a field here when it
  is a **credential** (the admin UI tells operators the export file is safe to commit to
  version control, so it must never carry secrets — `recaptchaSecretKey`, `recaptchaSiteKey`)
  or **runtime state rather than configuration** (`lastPostedStatCategory` is the community-
  stat round-robin cursor; restoring it would rewind the rotation). Anything else belongs in
  the export.
- **Narrative and world are still hand-maintained** literal field lists. Both sides —
  the export array *and* the `upsert…`/`import…` setters — have to be edited together.
  `FacilityTemplate` is the sharp edge: its export shape lives in `FacilityTemplate::toArray()`
  while its import lives in the service, so the two lists are separately maintained and have
  drifted before.

### The guard tests

Each service has a coverage test that fails when an entity gains a column the service does
not handle:

- `tests/Service/ConfigImportExportCoverageTest.php`
- `tests/Service/NarrativeFacilityTemplateRoundTripTest.php`
- `tests/Service/LeagueImportExportRoundTripTest.php`

A failure in one of these means **the export needs updating, not the test**. The only
legitimate way to make one pass without extending the export is to add the field to
`DENIED_PROPERTIES` (config) or to the test's explicit exclusion list (narrative, world) —
and that is a decision to make deliberately, for one of the reasons listed above.

## Import failures are per-field, not per-file

`ConfigImportExportService::import()` collects an error per bad field and applies everything
else; the narrative and world importers do the same per row. A hand-edited file with one
malformed enum must not cost the operator the other few hundred settings. Preserve that
behaviour when touching these services — an early `throw` out of the apply loop reintroduces
the all-or-nothing failure mode, and because Doctrine closes the EntityManager on a failed
flush, a partial failure is not recoverable after the fact.

A rejected row must also leave nothing behind. Both hand-maintained importers construct the
entity before they finish validating it, so a row that throws mid-way would otherwise be
written by the trailing flush — the operator is told the row failed and gets it anyway. The
narrative importers persist only once the row is known-good; the world importer detaches an
entity it created before re-throwing. Preserve whichever of the two a given method uses.

Related: `array_key_exists`, not `isset`, when reading an import row. Under `isset` a boolean
can only ever be imported as `true` and a `0` is skipped entirely, so a flag can be turned on
by import but never off.
