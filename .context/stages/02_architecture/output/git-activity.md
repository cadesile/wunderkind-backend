# Recent Git Activity

**Recent commits:**
```
1d4eb4a added season ticket 80% game config
759a64d docs: frontend API spec for POST /api/account/delete
63807e5 refactor: drop redundant service registration, log deletion failures, cover zero-club user
d4d0c86 docs: document POST /api/account/delete; cover LeaderboardEntry deletion
5e0bd89 feat: POST /api/account/delete endpoint
9ff7324 feat: AccountDeletionService — delete user, clubs, and all club dependents
402c052 docs: implementation plan for account deletion endpoint
dd8fff7 docs: verify account-deletion FK teardown against live schema
3d5bba7 docs: design for account deletion endpoint
4fc2b96 fix: bound world-pack agents to a players-per-agent ratio
06b9eab feat: expand nested agent snapshot to full record (reputation, experience, rating, nationality, dob)
c20d1a0 fix: move avatar compositor asset out of public/admin (was 403ing /admin)
e59624d feat: associate agents with players at world pack generation
bfc74a1 fix: include appearance in scout-search players and scout snapshot (final review)
3b122c4 fix: remove dead club references from StaffCrudController; prove appearance editor renders on Player/Staff/Scout edit pages
```

**Recently changed files:**
- `CLAUDE.md`
- `config/services.yaml`
- `docs/api/account-delete.md`
- `migrations/Version20260719000000.php`
- `src/Controller/Admin/DashboardController.php`
- `src/Controller/Api/AccountController.php`
- `src/Controller/Api/GameConfigController.php`
- `src/Entity/GameConfig.php`
- `src/Service/SyncService.php`
- `templates/admin/game_config.html.twig`
- `tests/Controller/Api/AccountControllerTest.php`
- `tests/Service/AccountDeletionServiceTest.php`
