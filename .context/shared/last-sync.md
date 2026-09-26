# Last Sync

> Updated by the acting agent every time any stage's `output/` is written
> (see `SKILL.md`'s "How to use this skill", step 5). Read by the
> session-start staleness check (see `SKILL.md`'s Triggers table) to find
> commits that have landed since `.context/` was last reviewed.

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui (restructured
  NpcClub's `identity` to nested `home`/`away` kit variants + shared badge;
  `NpcClub::setIdentity()` now syncs `primaryColor`/`secondaryColor` from
  `identity.home`, replacing `NpcClubGenerationService`'s old separate
  color-pair generator; `primaryColor`/`secondaryColor` no longer
  independently admin-editable; also fixed the shorts/socks/trousers picker
  in both this widget and the player appearance widget to render color chips
  instead of a full kit thumbnail, which couldn't show the selected part
  color at all)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui, 07_synthesis
  (NpcClub kit+badge `identity` config — new `KitIdentityType`/admin widget,
  `NpcClubGenerationService::generateIdentity()`, `LeagueImportExportService`
  export/import coverage; also synced `CLAUDE.md`'s NpcClub entry and added a
  "Kit & Badge Identity" section)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 01_overview (added `output/deployment.md` — the
  three-branch `development`→`dev`/`master` git merge/propagation policy; also
  synced `CLAUDE.md`'s Git Workflow section to match)

---

- **Commit:** dfc8035a8cb9f014abcf5777da6840bc1f18f7ef
- **Date:** 2026-09-18
- **Stages touched this pass:** 01_overview, 02_architecture, 03_data, 04_interfaces, 05_ui, 06_documentation, 07_synthesis (full warm — rebuilt from scratch after retiring the old generator-script-based `.context/`, previous content preserved in this repo's `git stash`)
