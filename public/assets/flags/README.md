# Country flag SVGs

Source: [flag-icons](https://github.com/lipis/flag-icons) v7.5.0, MIT licensed —
vendored here (4x3 ratio) rather than pulled from a CDN at runtime so the landing
page has no third-party request on its critical path and keeps working offline in
local dev.

**Filenames are `App\Enum\Country` codes, lower-cased — not the upstream codes.**
The two differ in exactly one place and it is the important one: England is
`gb-eng` upstream (a GB subdivision) and `en.svg` here. Mapping at download time
rather than at render time keeps the lookup a plain `code|lower ~ '.svg'` with no
translation table to drift.

`CountryFlagAssetTest` fails if a `Country` case ever has no file here, so adding
a country to the enum surfaces the missing flag as a red test rather than as a
broken image on the live page.

To add one: grab `https://cdn.jsdelivr.net/npm/flag-icons@7.5.0/flags/4x3/<code>.svg`
and save it under the matching `Country` code.

Note on weight: `es.svg` is ~81KB because the Spanish coat of arms is drawn in
full detail. It renders at 16px wide here, where none of that detail is visible,
but it is left unmodified so the files stay byte-identical to upstream and can be
re-fetched without a diff. Each file is requested at most once per page and then
browser-cached.
