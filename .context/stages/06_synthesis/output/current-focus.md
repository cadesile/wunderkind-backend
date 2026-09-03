# Current Development Focus

- **Dev environment shipped and live (2026-09-04)** — pushes to `dev` now deploy to
  `dev.buildmyclub.co.uk` / `api.dev.buildmyclub.co.uk`. See
  `stages/01_overview/output/deployment.md`. Three changes made it possible:
  TLS termination moved off the prod app container onto a shared host-level Caddy proxy
  (`deploy/proxy/`, `03db349`), the dormant `staging` stack was repurposed as `dev`
  (`c1cecef`), and `framework.yaml` gained `trusted_proxies` because the app now sits
  behind a proxy. **There is no staging tier any more.**
- **The app image no longer terminates TLS** — `docker/nginx.conf` is a single
  hostname-agnostic vhost, `docker/nginx-http-only.conf` and the certbot sidecar are gone,
  and the app containers bind no host ports. One image backs every environment.

- **Prod deploy pipeline stripped of OOM-causing steps** — `2785d21` ("Drop cache:clear from the prod deploy — OOM-killed, and wrong anyway") and `364413d` ("Drop appearance backfill from the prod deploy — it OOMs the container") both remove steps added in `6063a05` ("Add excursion seeding and appearance backfill to the prod deploy"), all touching `.github/workflows/deploy-prod.yml`.
- **New club-related service and API surface** — `91f1794` ("new service") accompanies changes to `src/Controller/Api/ClubController.php`, `src/Service/ClubInitializationService.php`, `src/Service/ClubNameNormalizer.php`, `src/Exception/ClubNameTakenException.php`, and `src/Repository/NpcClubRepository.php`.
- **Test isolation fix for account deletion** — `b5d7db5` ("Stop AccountDeletionServiceTest leaking a League row per run"), merged via `7b615bd`, modifies `tests/Service/AccountDeletionServiceTest.php`.
- **Player DOB and regional skin tone feature merged** — `15b8283` ("Merge branch 'feat/player-dob-and-regional-skin-tone'").
- **New excursion image asset added** — `public/uploads/excursions/graptor-kids-litter-picker-3-4cc3dd7495d9bab04be3f725c7bdda4874255eff.png`, tied to the excursion seeding work in `6063a05`.
