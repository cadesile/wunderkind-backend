# Recent Git Activity

**Recent commits:**
```
91f1794 new service
291d4ca Merge branch 'fix/deploy-drop-cache-clear'
2785d21 Drop cache:clear from the prod deploy — OOM-killed, and wrong anyway
9352640 Merge branch 'fix/deploy-drop-appearance-backfill'
364413d Drop appearance backfill from the prod deploy — it OOMs the container
7b615bd Merge branch 'fix/account-deletion-test-league-leak'
b5d7db5 Stop AccountDeletionServiceTest leaking a League row per run
e59368f Merge branch 'chore/deploy-excursions-and-appearance-backfill'
6063a05 Add excursion seeding and appearance backfill to the prod deploy
15b8283 Merge branch 'feat/player-dob-and-regional-skin-tone'
a9eb208 Weight avatar skin tone by world region; fix generated DOB age drift
eeb08d3 excursions
727544b latest landing page
21b28fa guest sign in changes
1213e98 updated images
```

**Recently changed files:**
- `.github/workflows/deploy-prod.yml`
- `public/uploads/excursions/graptor-kids-litter-picker-3-4cc3dd7495d9bab04be3f725c7bdda4874255eff.png`
- `src/Controller/Api/ClubController.php`
- `src/Exception/ClubNameTakenException.php`
- `src/Repository/NpcClubRepository.php`
- `src/Service/ClubInitializationService.php`
- `src/Service/ClubNameNormalizer.php`
- `tests/Service/AccountDeletionServiceTest.php`
