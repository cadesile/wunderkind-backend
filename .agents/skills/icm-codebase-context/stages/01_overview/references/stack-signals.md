# Stack signals beyond the standard manifests

A bare manifest-file scan misses project shapes that don't fit the usual
Node/PHP/Python/Go/Rust/Ruby mold. Check the actual directory contents, not
just which manifest files exist, especially when a manifest looks thin or
unrelated to the rest of the tree.

- **Shopify theme:** `sections/`, `templates/`, `snippets/`, `layout/`,
  `locales/*.json`, `config/settings_schema.json`, many `.liquid` files.
  Often paired with a `package.json` containing `@shopify/theme-*`
  dependencies. A `composer.json` present alongside this is very likely
  unrelated tooling, not the primary stack.
- **Static site generators:** `content/`, `_posts/`, a `config.toml`/`.yaml`
  at the root, no server-side framework dependency at all.
- **Monorepo:** multiple `package.json`/`composer.json` files in sibling
  subdirectories, each a real, independent app — don't assume there is only
  one "primary" stack; the human may want more than one `shared/stack.md`
  entry, or a per-package breakdown. Ask at the checkpoint rather than
  guessing which one is "the" app.
