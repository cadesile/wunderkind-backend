# Stage 04: Interfaces — Routes, Controllers, Services & External API

## Inputs

| Source | What to look for |
|---|---|
| `.context/shared/stack.md` | Framework — determines where routes/controllers actually live |
| `.context/stages/03_data/output/` | Entities these interfaces operate on — reference, don't restate |
| Route definition files, controller/handler code, service-layer code | The actual, current request surface |
| OpenAPI/Swagger file, if one exists (`openapi.yml`, `swagger.json`, etc.) | A human-authored contract — treat as a cross-check on what you find in code, not a replacement for reading the code |
| Existing `output/` in this stage, if present | See `../03_data/references/revise-not-rewrite.md` — same rule applies here |

## Process

1. Read `.context/shared/stack.md` first to know the routing convention in
   use (file-based routes, a central router file, annotations, etc.).
2. Enumerate the real routes/endpoints from the actual route-definition
   code, not from any README or comment claiming what exists.
3. For each route, trace into its controller/handler and note which
   service-layer code it calls — enough to answer "what does this endpoint
   actually do," not a line-by-line trace.
4. If an OpenAPI/Swagger file exists, cross-check it against what you found
   in code; note any place they disagree rather than silently picking one.
5. Apply the revise-not-rewrite rule from stage 03 if `output/` already has
   content.

## Audit

Before writing `.context/stages/04_interfaces/output/*.md`:

- [ ] Every route/endpoint listed traces to route-definition code you opened this run
- [ ] Any OpenAPI/code mismatch found in step 4 is noted, not silently resolved
- [ ] Hand-added human content from a previous run, if any, was preserved

## Outputs

| File | Content |
|---|---|
| `.context/stages/04_interfaces/output/routes.md` | Routes/endpoints and what triggers them |
| `.context/stages/04_interfaces/output/controllers.md` | Controller/handler responsibilities |
| `.context/stages/04_interfaces/output/services.md` | Service-layer responsibilities |
| `.context/stages/04_interfaces/output/api-spec.md` | Only if an OpenAPI/Swagger file exists — summary plus any mismatch with code |
