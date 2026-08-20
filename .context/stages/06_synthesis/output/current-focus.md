# Current Development Focus

- **Prod deploy pipeline stripped of OOM-causing steps** — `2785d21` ("Drop cache:clear from the prod deploy — OOM-killed, and wrong anyway") and `364413d` ("Drop appearance backfill from the prod deploy — it OOMs the container") both remove steps added in `6063a05` ("Add excursion seeding and appearance backfill to the prod deploy"), all touching `.github/workflows/deploy-prod.yml`.
- **New club-related service and API surface** — `91f1794` ("new service") accompanies changes to `src/Controller/Api/ClubController.php`, `src/Service/ClubInitializationService.php`, `src/Service/ClubNameNormalizer.php`, `src/Exception/ClubNameTakenException.php`, and `src/Repository/NpcClubRepository.php`.
- **Test isolation fix for account deletion** — `b5d7db5` ("Stop AccountDeletionServiceTest leaking a League row per run"), merged via `7b615bd`, modifies `tests/Service/AccountDeletionServiceTest.php`.
- **Player DOB and regional skin tone feature merged** — `15b8283` ("Merge branch 'feat/player-dob-and-regional-skin-tone'").
- **New excursion image asset added** — `public/uploads/excursions/graptor-kids-litter-picker-3-4cc3dd7495d9bab04be3f725c7bdda4874255eff.png`, tied to the excursion seeding work in `6063a05`.
