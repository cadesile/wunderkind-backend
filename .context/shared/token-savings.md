# Token Savings Estimate

> Estimated, not exact — a chars÷4 heuristic (roughly 1 token per 4
> characters), not a real tokenizer. Treat these numbers as directional:
> good for seeing the shape of the saving, not for precise budgeting.
> Updated by the acting agent every time any stage's `output/` is written
> (see `SKILL.md`'s "How to use this skill", step 5). Measure file sizes
> yourself with your own tools (e.g. `wc -c`) — no bundled script computes
> this for you, consistent with the rest of this skill.

| Load scope | Est. tokens |
|---|---|
| Everything (router + `.context/stages/*/output/*.md`, all stages) | 128051 |
| Router only (`.context/CONTEXT.md`) | 572 |
| `01_overview` (router + its `output/`) | 8132 |
| `02_architecture` (same shape) | 5937 |
| `03_data` (same shape) | 46389 |
| `04_interfaces` (same shape) | 53445 |
| `05_ui` (same shape) | 6410 |
| `06_documentation` (same shape) | 4964 |
| `07_synthesis` (same shape) | 6204 |

**Typical saving vs. loading everything:** ~85% (average across all seven stages — 58%–97%
per stage, `04_interfaces` lowest on its own at ~58%, `06_documentation` highest)

**Last updated:** 2026-11-15 — **full re-measurement of all seven stages**, after the economy
loose-ends pass wrote to `03_data/output/entities.md` + `migrations.md`,
`04_interfaces/output/services.md` + `routes.md` + `controllers.md`, and
`shared/api-backwards-compatibility.md`.

**Every row roughly doubled or better since the 2026-10-24 reading, and almost none of that is
this pass.** `Everything` 52,193 → **127,002**; `03_data` 17,477 → 46,389 and `04_interfaces`
21,412 → 52,855 are the bulk of it. Ten commits landed in that window (the admin club buyout,
the world-pack version work, the starter-pack check) and the 2026-11-14 buyout pass wrote
heavily to both stages without re-measuring. Measuring all seven is what makes that visible
rather than attributing someone else's 70,000 tokens to this one; the same argument the app
repo's copy of this file makes twice over.

This pass's own contribution is small by comparison — one new section in `entities.md`
(the five post-`Version20261112160100` economy columns), one in `migrations.md`
(`Version20261115190000`), the `SponsorBandResolver`/`TicketPriceResolver` entries in
`services.md`, and edits to two rows of `routes.md` and one section of `controllers.md`.

⚠️ **`03_data` and `04_interfaces` are now 37% and 42% of a full load on their own.** Between
them they are two thirds of everything. `entities.md` is the single largest file and is worth
watching: the economy work has added five sections to it since 2026-11-12.

**Re-measured 2026-11-15 (second reading that day) — ALL SEVEN stages**, after the backlog
review wrote `packVersion` into `04_interfaces/output/routes.md` + `controllers.md` and added
`docs/api/world-pack-version.md` to `06_documentation/output/index.md`. `Everything`
127,002 → **128,051 (+1,049)**: `04_interfaces` +590, `06_documentation` +459, the other five
and the router byte-identical. The arithmetic closes exactly, which is the cheap confirmation
that the measuring method matched the reading taken earlier today.
