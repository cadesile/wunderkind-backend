# Location enrichment — configuring the AI provider

Towns are seeded with a name, a country, a region and a population. **Coordinates and land
valuations are not in the seed data at all** — there is no lat/lng anywhere in the PHP the
world was built from. Enrichment fills those in by asking an AI provider, once per town,
and persisting the answer.

**It costs money per row.** One billed API call per town, 865 towns per full sweep, plus a human
review pass over 865 AI-sourced land valuations. Everything below is shaped around not paying
for that twice.

---

## 1. Configuration — three environment variables, no code change

| Variable | Purpose |
|---|---|
| `LOCATION_ENRICHER_PROVIDER` | `anthropic` (or `claude`) to turn it on. **Empty by default**, which means off. |
| `LOCATION_ENRICHER_MODEL` | e.g. `claude-sonnet-5`. Stored on every row it produces. |
| `LOCATION_ENRICHER_API_KEY` | **A secret.** |

**Yes — set these as deploy variables.** They follow `FIREBASE_SERVICE_ACCOUNT_JSON`, the only
other external credential in this codebase: env vars, injected per environment, held as
`{DEV,PROD}_LOCATION_ENRICHER_*` secrets in the deploy workflow.

**The key must not go in `.env`** — that file is committed. Local work goes in `.env.local`;
deployed environments get it from the secret store.

**And not in the database either.** The admin panel is a far broader surface than a deploy
secret, a key in a column lands in every backup, and the config export is documented to admins
as safe to commit to version control. `ConfigImportExportService::DENIED_PROPERTIES` exists
because that lesson was already learned with the reCAPTCHA keys.

### Why it is a factory, not a service alias

`LocationEnricherFactory` reads the provider variable and returns either
`ClaudeLocationEnricher` or `UnconfiguredLocationEnricher`. A Symfony service *alias* needs a
concrete service id at compile time, so `alias: '%env(...)%'` does not compile — selecting a
provider by environment variable has to happen in a factory.

**Anything it does not recognise falls back to refusing loudly, and says so in the log.** A
typo'd provider, a missing key, an empty model: all of them yield the unconfigured enricher,
which throws and reports not-ok on the admin connection check. It never falls back to doing
nothing quietly — an enricher that returned empty results would be indistinguishable from a
provider answering unhelpfully, and an 865-row sweep would land in `failed` with no indication
why. That is not hypothetical: a missing `FIREBASE_SERVICE_ACCOUNT_JSON` once discarded every
push notification in this codebase with zero trace.

### Check the connection before the first sweep

The admin enrichment screen (**Locations & Land → Location Enrichment**) shows a connection
check: one token of output against the configured model, costing effectively nothing. Click it
first. An unconfigured setup discovered after 865 rows have each recorded a failure is the
incident this button exists to prevent.

---

## 2. Which model

The model is a deploy variable specifically so this is a redeploy, not a code change. Whatever
is set is written to `location.enrichment_model` on every row it produces, so a change is
visible per location rather than inferred from timestamps.

| Model | When |
|---|---|
| `claude-haiku-4-5-20251001` | Cheapest bulk sweep. Fine for coordinates and population. |
| `claude-sonnet-5` | **The default.** Good balance for valuation judgement. |
| `claude-opus-5` | When valuation plausibility needs to be better and the review queue is the bottleneck. |

**Cost is not the deciding factor at this volume.** At roughly 400 input / 200 output tokens per
town, a full 865-row sweep is about half a million tokens — single-digit dollars on any of the
above, and the Batch API halves it again. The real budget is the **review effort** on 865
valuations, which is what `needs_review` and the admin queue exist to manage. So pick on output
quality, not price: a better model means fewer rows to review by hand.

---

## 3. How the request is made

`ClaudeLocationEnricher` posts once to `/v1/messages` over the shared `HttpClientInterface` —
the same way `YouTubeFeedService` and `SocialPostingService` talk to external APIs. No vendor
SDK: it is one POST, and a Composer dependency for one endpoint is supply-chain surface for
nothing.

**Structured outputs is the important part.** The request carries
`output_config.format.type: json_schema` with every field **required and nullable**:

- *Required* means the model cannot silently omit `lng` — the failure that produces a
  half-enriched row which looks fine until a rival distance is computed from it.
- *Nullable* means it can decline a field honestly, which is a much better answer than a
  confident guess. The validator reads a null as "not supplied", not as a rejection.

**Land value is asked for in POUNDS, then multiplied by 100.** A model asked for pence loses
track of the factor of 100 across a wide range, and an answer wrong by 100× falls inside the
validator's plausible band *for a different town* — so it would be accepted rather than caught.

The prompt states the accuracy bar explicitly as **believable realism, not precision**. A model
told to be precise about something it cannot be precise about either refuses or manufactures
false confidence; the honest framing produces better answers than either.

---

## 4. Nothing it returns is trusted

`EnrichmentValidator` decides, **per field**, never per response:

- `lat`/`lng` must fall inside the country's stored bounding box. This single check kills the
  `0,0`-in-the-Atlantic case and most same-name-different-country errors.
- `population` must be plausible, and within a ratio of any seeded value — a large divergence
  sets `needs_review` and **keeps the seeded figure**.
- `land_pence` must be positive and inside the country's band, so one hallucinated valuation
  cannot price a plot at £4bn.

Accepted fields are written; rejected ones are left null and named in the attempt's
`rejected_fields`. Any rejection lands the row in `needs_review`, not `enriched` — **the status
explains its own exclusions.** All fields rejected lands it in `failed`.

**Admin corrections win permanently.** Editing coordinates, population or land value in the
admin sets that field's lock, and enrichment skips locked fields for ever after. A re-run can
never undo a human correction.

---

## 5. Export and import — enrich once, move the result

This is the part that stops dev, prod and every rebuilt database from paying for the same 865
towns again.

```bash
# On the environment that has been enriched and reviewed
lando php bin/console app:locations:export EN --output=locations-en.json

# Anywhere else — always dry-run first
lando php bin/console app:locations:import locations-en.json --dry-run
lando php bin/console app:locations:import locations-en.json
```

Also on the admin enrichment screen: a country picker with **Download JSON**, and an upload with
a **dry run** box ticked by default.

### What travels

Country profiles (**including the bounding box**, because without it the target environment
cannot validate a re-enrichment), locations with coordinates, population, land values,
provenance, **locks** and enrichment status, and their plots.

### What deliberately does not

- **`plot_claim`, `plot_hold`, `user_ledger`.** Real money and real player data. Moving a claim
  between environments would be moving somebody's purchase.
- **`location_rival`, `stadium_estimate`.** Derived caches, both rebuildable and both keyed by
  uuid — carrying them would mean an id-mapping pass for data that costs nothing to recompute.
- **`enrichment_claimed_at`.** A lease belongs to the environment whose worker took it.
- **`location_enrichment_run` / `_attempt`.** Forensic history, specific to where it happened.

### Two rules the import follows

**Matched on `(countryCode, slug)`, never on id.** UUIDs differ between environments, and a file
that assumed otherwise would only import cleanly into the database it came from. Existing rows
keep their own ids, which is what keeps claims pointed at the right towns.

**Locks in the target win.** A field a human has locked *here* is skipped and counted, not
overwritten. Importing geography must not undo the review the file exists to carry. Locks also
travel *with* the data, so a reviewed town arrives both correct and protected.

**There is no `--clear`, unlike the world-content importer.** `location` is referenced by
`plot_claim` with `ON DELETE RESTRICT` precisely because a town can carry real money, so a
truncate-then-import is either refused by the database or means deleting somebody's purchase
first. Import is always an upsert, and deletes nothing.

### After an import

Two things are invalidated rather than carried, and need rebuilding:

```bash
lando php bin/console app:locations:rivals --stale-only      # rival sets
lando php bin/console app:plots:refresh-estimates            # stadium prices
```

---

## 6. The normal working order

```
app:locations:seed EN                              # towns, one plot each, all pending
app:locations:enrich EN --limit=3 --sync           # iterate the prompt on three rows
   ... check the connection on the admin screen ...
admin: enrich all remaining for EN                 # the sweep
admin: work the needs_review queue                 # the real budget
app:locations:rivals --country=EN                  # once enrichment settles
app:locations:export EN -o locations-en.json       # so nobody pays for this twice
```

`--sync` runs the enricher in-process for one or two rows, which is how to iterate on the prompt
without a worker. `--dry-run` reports what would be queued and calls nothing.

**Rivals must run after enrichment settles, not once.** Nearest-4 is computed from coordinates,
so a newly enriched town changes *other* towns' rival sets. The enrichment handler nulls
`rivals_computed_at` on every location within the search radius, which is what makes
`--stale-only` pick them up — but until a country is fully enriched its rival sets are
provisional. That is invisible in practice, because `country_profile.status` keeps the country
out of the API until it is ready.

### Prod

`docker/enrichment-consume.sh` drains the queue every minute, with a low `--limit` that is a
**cost control** as much as a memory one: a runaway sweep stays bounded by the cron cadence
rather than draining the budget in one run.
