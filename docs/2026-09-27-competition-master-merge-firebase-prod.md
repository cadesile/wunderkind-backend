# Merge `competition` into `master` (backend) + Firebase prod key + frontend handoff

## Context

The `competition` branch (Phase 1 competition framework: entities, eligibility/registration
API, round processing, match engines, and a first-time push-notification subsystem via FCM)
has been merged into `dev` repeatedly for staging/testing, but never into `master`. Shipping
it to production is blocked on two things beyond a normal merge:

1. **A new secret has to exist in production that doesn't today.** The branch adds
   `kreait/firebase-bundle` + Symfony Messenger to send FCM push notifications
   (`src/Service/Notification/PushNotificationService.php`). It reads a raw Firebase
   service-account JSON from `FIREBASE_SERVICE_ACCOUNT_JSON`. `DEV_FIREBASE_SERVICE_ACCOUNT_JSON`
   already exists as a GitHub Actions secret (added 2026-09-20, after an incident where it was
   missing and pushes failed silently — see `docs/deploy/hetzner.md`'s "Four traps" section).
   `PROD_FIREBASE_SERVICE_ACCOUNT_JSON` does not exist yet (confirmed via `gh secret list`) —
   this is the "Firebase related deployment key" this merge needs.
2. **Push notifications only work once the frontend ships too.** The backend side is additive
   and harmless on its own (new routes/entities, no existing behavior changes), but the app's
   production build (`com.buildmyclub`) has no Firebase Android app registered yet in the
   shared `build-my-club` Firebase project, and iOS FCM delivery is explicitly flagged as
   unverified in the frontend's own push-notification plan doc. This plan treats the frontend
   side as an external handoff/checklist, not something to execute here.

Decisions made when this plan was drafted: backend-repo scope only (frontend tracked as a
handoff); the new prod secret is a **dedicated service account** (not a copy of dev's); **iOS
APNs setup is included** as a required step; rollout is **backend to master first**, then a
handoff to ship the app.

## 1. Catch `competition` up with `development` before merging it in

`origin/competition` is currently missing 8 commits that already landed on `development`
(Facility Manager/DOF/scout staff config, owner identity + `/api/owner-avatar`, the sprite/kit
rebuild, GA on the landing page). Per the documented policy
(`.context/stages/01_overview/output/deployment.md`), new work always lands on `development`
first, propagated by merge, never cherry-pick:

```bash
git checkout competition
git pull origin competition
git merge origin/development   # resolve any real conflicts — none expected, but verify
lando php vendor/bin/phpunit --no-coverage
```

Watch specifically for:
- The pre-existing, unrelated flake noted in `.context/shared/last-sync.md`:
  `RewardApplierServiceTest::testApplyingTwiceDoesNotDoubleGrantTheReward`. Confirm it's still
  failing on `development` tip alone (not something this merge introduces) before ignoring it.
- `LeagueImportExportRoundTripTest` / `ConfigImportExportCoverageTest` /
  `NarrativeFacilityTemplateRoundTripTest` — these fail on purpose when an entity gains a column
  the export services don't handle. `competition` adds no config/league/narrative entities, so
  they should be unaffected, but they're worth a specific look given how much schema this branch
  adds.

Push the caught-up `competition` branch, then merge it into `development`:

```bash
git checkout development
git merge --no-ff competition
lando php vendor/bin/phpunit --no-coverage
git push origin development
```

## 2. Firebase: create the production service account

In the Firebase console, project **`build-my-club`** (the same project dev already uses):

1. Project Settings → Service Accounts → **Generate new private key**, but first create a
   **new, dedicated service account** scoped to Firebase Cloud Messaging only (don't reuse the
   default App Engine service account or dev's key) — this is the "new dedicated service
   account" decision above, so prod and dev credentials can be rotated/revoked independently.
2. Download the JSON, then minify it before it ever touches a GitHub secret — this is a
   documented trap (`docs/deploy/hetzner.md`): the deploy workflow's heredoc strips leading
   whitespace from every line of `.env`, which corrupts a pretty-printed multi-line JSON
   (especially the private key's embedded `\n` sequences):
   ```bash
   jq -c . prod-service-account.json | gh secret set PROD_FIREBASE_SERVICE_ACCOUNT_JSON --repo <org>/wunderkind-backend
   ```
3. **Upload the APNs auth key** for iOS delivery: Firebase console → Project Settings → Cloud
   Messaging → Apple app configuration → upload the `.p8` APNs Authentication Key (with its Key
   ID and Team ID) against whichever iOS app is registered in this project. This was flagged as
   not yet done and is a hard requirement before iOS push can work at all — FCM can't deliver to
   an iOS device's raw APNs token without it.
4. Delete the local copy of the downloaded JSON once the secret is set.

No other new secret is needed — `MESSENGER_TRANSPORT_DSN` defaults to
`doctrine://default?auto_setup=1` in both compose files already, which is already the correct
production value (self-creates its table on first dispatch).

## 3. Verify master vs. development before merging

Per policy rule 4, check for master-only commits that haven't flowed back to `development`
before merging into `master`:

```bash
git fetch origin
git log origin/development..origin/master --oneline
```

As of this session this was empty in substance (two merge commits that already fold
`development`'s content into `master`) — re-run this immediately before merging, since more
direct `master` fixes may have landed since.

## 4. Merge `development` into `master`

```bash
git checkout master
git pull origin master
git merge --no-ff development
lando php vendor/bin/phpunit --no-coverage   # verify the merged tree, not before
git push origin master   # this triggers a real prod deploy — confirm with the user first
```

Pushing `master` runs `.github/workflows/deploy-prod.yml`, which (once `competition` is merged
in) will:
- Write `FIREBASE_SERVICE_ACCOUNT_JSON` from the new `PROD_FIREBASE_SERVICE_ACCOUNT_JSON` secret
  into the server's `.env`, and inject it into the `app` container's `environment:` block
  (already fixed on `competition` — this was the exact bug the "Four traps" section documents).
- Run migrations (Competition entities, `NotificationLog`, `DeviceToken`, Messenger's
  `messenger_messages` table).
- Run `app:seed-match-narrative` (idempotent, skip-existing-slug — already wired into
  `deploy-prod.yml` on this branch).
- Start `docker/messenger-consume.sh` draining the async transport every minute (already baked
  into the image's crontab).

## 5. Post-deploy verification

1. Hit the admin **Notification debug** page (`NotificationDebugController`, added by this
   branch) and run its Firebase connection validation (`FirebaseConnectionValidator`) — this
   exists specifically to catch a misconfigured/missing service account without needing a real
   device. Confirm it reports a valid connection against the new prod credentials.
2. Force-trigger one of the four push types at a test club via the same debug page and confirm
   a `NotificationLog` row is written with a success status (not `FAILED`), so you've verified
   the whole path (credential → Messenger dispatch → FCM send → logged) before any real device
   depends on it.
3. Spot-check `GET /api/competitions/active` and `POST /api/device-tokens` respond correctly
   against prod.

## 6. Frontend handoff (tracked here, executed by the frontend side)

Backend is safe to ship alone — nothing existing changes behavior, and unused new routes are
harmless. But push notifications, and the competition UI generally, do nothing until the app
ships its own `competition` work. Hand this checklist over (already documented in
`wunderkind-app`'s `scripts/README.md` and its
`docs/superpowers/plans/2026-09-19-push-notifications-competition-triggers.md`):

- Register the production Android app **`com.buildmyclub`** in the `build-my-club` Firebase
  console project (only `com.buildmyclub.dev` exists there today), download its
  `google-services.json` to the frontend repo root, and add
  `"googleServicesFile": "./google-services.json"` under `expo.android` in `app.json`.
- Confirm the iOS APNs key from step 2.3 above is active, then **test push delivery on a real
  iOS device** — the frontend's own plan doc is explicit that this can't be assumed from the
  Android result and must be verified before relying on it.
- This is a **native config change**, not an OTA — requires a fresh EAS build. A production
  build made before `google-services.json` exists will silently register tokens that can never
  receive a push.
- Frontend's own `development` branch is 5 commits ahead of its `master`, and its `competition`
  branch needs `development` merged into it first (same pattern as backend) before it can go to
  `master` — that sequencing is the frontend team's / your own follow-up, not part of this plan.

## Verification summary

- `lando php vendor/bin/phpunit --no-coverage` green on the merged `development` tree and again
  on the merged `master` tree (policy rule 5 — verify the merge, not the pre-merge branch).
- `FirebaseConnectionValidator` (admin debug page) reports success against
  `PROD_FIREBASE_SERVICE_ACCOUNT_JSON` post-deploy.
- One real end-to-end push (any of the 4 types) logged as delivered in `NotificationLog` in
  production before telling the frontend team it's safe to point a build at it.
