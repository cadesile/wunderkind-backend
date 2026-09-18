# Revise, don't rewrite

When this stage's `output/` already has content from a previous run:

- Treat any content a human clearly added or edited by hand as
  authoritative — preserve it, don't regenerate over it.
- Re-verify every generated fact against the current code before keeping
  it; code changes since the last run, so "it was true last time" is not
  good enough.
- If you can't tell whether a passage was human-added or generated, err
  toward keeping it and asking the human at the next checkpoint rather than
  deleting it.
