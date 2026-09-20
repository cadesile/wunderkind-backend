# Domain Entities & Relationships

All facts below trace to `src/Entity/*.php` files opened during this pass.
Doctrine entity attributes are the authoritative current schema —
migrations are the change log (see `migrations.md`).

## Auth / user

- **`User`** — auth identity (`UserInterface`). `email`, `password`,
  `roles:array`, `managerProfile:?array`, `isVerified`, `verifiedAt`,
  `lastLoginAt`, `createdAt`. `OneToMany` → `clubs:Collection<Club>`
  (cascade persist/remove).
- **`Admin`** — separate back-office auth identity (`UserInterface`).
  `email`, `password`, `name`, `department`, `accessLevel:int(1)`.
  `getRoles()` hardcodes `['ROLE_ADMIN']`. No entity relations.
- **`Guardian`** — a player's guardian. `firstName/lastName`, `gender`,
  `dateOfBirth`, `contactEmail`, `demandLevel:int(5)`,
  `loyaltyToClub:int(50)`. `ManyToOne` → `player:Player` (not nullable).
- **`EmailVerification`** — verification codes. `code`, `expiresAt`,
  `purpose:VerificationPurpose(enum)`, `attempts`, `verifiedAt`.
  `ManyToOne` → `User` (not nullable, `onDelete:CASCADE`).
- **`RefreshToken`** — extends `BaseRefreshToken` from
  `gesdinet/jwt-refresh-token-bundle`, table `refresh_tokens`.
- **`BetaRequest`** — beta signup gate. `email`, `code`, `valid:bool`,
  `attempts`, `expiresAt/createdAt`, `verifiedAt/invitedAt`.
- **`DeletionRequest`** — GDPR deletion tracking. `email`,
  `status:DeletionRequestStatus(enum)`, `ipAddress`, `failureReason`,
  `clubsDeleted:int`, `requestedAt/completedAt`.
- **`UserDevice`** (table `user_device`) — an FCM registration token
  for one app installation (push notifications). `deviceToken`
  (**unique across the table, not per-user** — a token identifies an
  installation, not an account; re-registering an existing token under
  a different user reassigns it), `platform:DevicePlatform(enum: ios,
  android)`, `deviceId:?string` (client-generated, unused so far),
  `lastActiveAt/createdAt`. `ManyToOne` → `User` (not nullable,
  `CASCADE`). Registered via `POST /api/device-tokens`, sent to by
  `PushNotificationService` — see `services.md`.
- **`NotificationLog`** (table `notification_log`) — audit record of one
  push-related Messenger message actually processed (success or failure),
  one row per `SendPushNotificationMessage`/
  `ResolveAdminMessageAudienceForPushMessage` handled. `status:NotificationLogStatus(enum:
  SUCCESS, FAILED)`, `messageType:string` (short class name), `summary`,
  `detailJson:array` (message properties + exception detail on failure),
  `errorMessage:?string` (truncated to 255, same convention as
  `DeletionRequest::$failureReason`), `createdAt`. No entity relations —
  written by `NotificationLoggingSubscriber` (see `services.md`), not by
  the handlers themselves, since that's the only place that uniformly
  catches both a handler's own exception and a handler-*construction*
  failure (the exact bug class — a missing `FIREBASE_SERVICE_ACCOUNT_JSON`
  — that motivated this table).

## Core game / club

- **`Club`** — the player's club, central aggregate. `name`, `reputation`,
  `totalCareerEarnings`, `hallOfFamePoints`, `lastSyncedWeek`,
  `lastSyncedAt`, `marketPoolSize:int(20)`, `financialYearStart:int(4)`,
  `country`, `abbreviation`, `worldInitializedAt/starterInitializedAt/
  tutorialCompletedAt`, `paName`, `managerTemperament/managerDiscipline/
  managerAmbition:int(50)`, `balance:int`, `managerProfile:?array`,
  `currentSeason:int(1)`, `formation:Formation(enum, F_442)`,
  `fanCount/fanSentiment/fanMorale`, `lastWeeklyAttendance/
  totalSeasonAttendance`, `isSpoof:bool(false)` (added by migration
  `Version20260918205514`). Relationships: `ManyToOne` → `User` (not
  nullable); `OneToMany` → `transfers`, `syncRecords` (cascade
  persist/remove), `leaderboardEntries` (cascade persist/remove),
  `investors`, `sponsors`, `inboxMessages` (cascade persist/remove);
  `ManyToOne` → `currentLeague:?League` (nullable).
- **`ClubFacility`** — a club's built facility instance. `facilitySlug`,
  `level`, `updatedAt`. `ManyToOne` → `Club` (not nullable, `CASCADE`).
- **`FacilityTemplate`** — facility catalog (not a relation target of
  `ClubFacility` by FK — joined via `facilitySlug`). `slug/label/
  description`, `category`, `baseCost`, `weeklyUpkeepBase`,
  `matchdayIncome/Multiplier`, `reputationBonus`, `maxLevel:int(5)`,
  `decayBase`, `gameplayEffects:array`, `baseConstructionWeeks:int(4)`,
  `sortOrder`, `isActive`, `imagePath`.
- **`League`** — a competitive tier. `country`, `tier`, `name`,
  `promotionSpots`, `tvDeal`, `leagueReputationTier:?ReputationTier(enum)`,
  `prizeMoney`, `leaguePositionPot`, `sponsorCount`, `trophyImage`,
  `trophyColour:?TrophyColour(enum)`. `OneToMany` → `leagueSponsors`
  (cascade persist/remove, orphanRemoval); `ManyToMany` → `sponsors`
  (explicit `JoinTable`, `onDelete:CASCADE`).
- **`LeagueSponsor`** — league↔sponsor income join (table
  `league_sponsor_income`). `rolledValue:int`. `ManyToOne` → `League`,
  `Sponsor` (both `CASCADE`).
- **`NpcClub`** — AI-controlled club. `name/country`, `tier/reputation`,
  `primaryColor/secondaryColor`, `abbreviation`, `stadiumName`, `balance`,
  `playingStyle('DIRECT')`, `financialApproach('BALANCED')`,
  `managerTemperament:int(50)`, `facilities:array`, `region`,
  `citySize:CitySize(enum)`, `populationSize`, `isCapital`,
  `formation:Formation(enum)`. `ManyToOne` → `League` (nullable).
- **`Transfer`** — a transfer event record. `playerName/playerPosition/
  clubLeaving`, `destinationClubName`, `type:TransferType(enum)`,
  `fee/agentCommission/netProceeds/developmentPoints/reputationGained`,
  `buyingClub`, `occurredAt/syncedAt`. `ManyToOne` → `Player` (nullable,
  `SET NULL`), `Club` (nullable, `SET NULL`).
- **`SyncRecord`** — one client sync event. `clientWeekNumber`,
  `clientTimestamp/serverTimestamp`, `payload:array`, `debugLog:?array`,
  `isValid`, `invalidReason`, `isRollback`. `ManyToOne` → `Club` (not
  nullable).
- **`SeasonRecord`** — end-of-season league result. `season/
  finalPosition/gamesPlayed/wins/draws/losses/goalsFor/goalsAgainst/
  points:int`, `promoted/relegated:bool`. `ManyToOne` → `Club`, `League`
  (both not nullable).
- **`SeasonSnapshot`** — season point-in-time snapshot. `season`,
  `country`, `snapshotData:array`. `ManyToOne` → `Club` (not nullable).
- **`SeasonRatingsSnapshot`** — denormalized ratings snapshot; stores
  `clubId`/`clubName` as plain strings, no FK relation. `season/weekNum/
  tier`, `overallRating`, `expectedPosition`.
- **`MatchResult`** — a played match's result. `goalsFor/goalsAgainst/
  week/season`, `fixtureId`, `opponentClubName`, `isHome`, `homeGoals/
  awayGoals`, `round`, `playedAt`, `yellowCards/redCards`. `ManyToOne` →
  `Club` (not nullable).
- **`LeaderboardEntry`** — one club's score in one leaderboard category.
  `category:LeaderboardCategory(enum)`, `score`, `period`, `rank`,
  `displayLabel`. `ManyToOne` → `Club` (not nullable).
- **`TacticalAdvantage`** — lookup table of style×style multipliers.
  `style/opponentStyle:PlayingStyle(enum)`, `multiplier:float`. No
  relations.

## Player / squad

- **`Player`** — a footballer. `firstName/lastName`, `dateOfBirth`,
  `nationality`, `position:PlayerPosition(enum)`,
  `status:PlayerStatus(enum, ACTIVE)`,
  `recruitmentSource:RecruitmentSource(enum)`, `potential/currentAbility/
  contractValue`, `pace/technical/vision/power/stamina/heart`,
  `height/weight`, `morale:int(50)`, `appearance:?array`. Embeds
  `personality:PersonalityProfile` (Embeddable — see below, not its own
  table). `OneToMany` → `guardians` (cascade persist/remove,
  orphanRemoval); `ManyToOne` → `agent:?Agent` (`SET NULL`);
  self-referential `ManyToMany` → `siblings:Collection<Player>`
  (`JoinTable: player_siblings`).
- **`PersonalityProfile`** — `#[ORM\Embeddable]`, **not a standalone
  entity/table**. `determination/professionalism/ambition/loyalty/
  adaptability/pressure/temperament/consistency:int(10)`, all on a 1–20
  scale (per docblock). Embedded into `Player`, `Scout`, `Staff`.
- **`PlayerArchetype`** — catalog of personality archetypes. `slug/name/
  description`, `polarity:ArchetypePolarity(enum)`,
  `traitWeights:array`. No relations.
- **`PlayerCareerStat`** — current cumulative career stat row.
  `playerId/playerName`, `appearances/goals/assists`. `ManyToOne` →
  `Club` (not nullable, `CASCADE`).
- **`PlayerCareerStatSnapshot`** — point-in-time copy of the above, tied
  to a sync. `playerId/playerName`, `appearances/goals/assists`,
  `recordedAt`. `ManyToOne` → `Club` (not nullable, `CASCADE`),
  `syncRecord:?SyncRecord` (nullable, `SET NULL`).
- **`Agent`** — a player's agent. `name`, `reputation:int(50)`,
  `commissionRate('10.00')`, `dob`, `nationality`, `judgements:array`,
  `experience`, `rating:int(50)`, `appearance:?array`. `OneToMany` →
  `players:Collection<Player>`.
- **`Scout`** — a club's scout. `name`, `dob`, `nationality`,
  `judgements:array`, `experience`, `appearance:?array`. Embeds
  `personality:PersonalityProfile`.
- **`Staff`** — coaching/other staff. `firstName/lastName`,
  `role:StaffRole(enum)`, `coachingAbility/scoutingRange:int(50)`,
  `weeklySalary`, `morale:int(50)`, `nationality`, `specialty`,
  `specialisms:?array`, `dob`, `hiredAt`, `appearance:?array`. Embeds
  `personality:PersonalityProfile`.

## Sponsorship / finance

- **`Sponsor`** — `company`, `nationality`, `size:CompanySize(enum)`,
  `isActive`, `monthlyPayment`, `contractStartDate/EndDate`,
  `reputationMinThreshold`, `reputationBonusThreshold`,
  `bonusMultiplier`, `status:SponsorStatus(enum)`,
  `earlyTerminationFee`, `assignedAt/lastPaymentAt`. `ManyToOne` →
  `club:?Club` (nullable — unassigned pool = null club).
- **`Investor`** — `company`, `nationality`, `size:CompanySize(enum)`,
  `isActive`, `tier:InvestorTier(enum)`, `investmentAmount`,
  `percentageOwned('5.00')`, `assignedAt/investedAt/lastPayoutAt`.
  `ManyToOne` → `club:?Club` (nullable).

## Messaging / admin comms

- **`AdminMessage`** (table `admin_message`) — operator announcement.
  `title`, `bodyHtml`, `targetType:MessageTargetType(enum)`,
  `priority:MessagePriority(enum)`,
  `displayType:MessageDisplayType(enum)`, `validFrom/Until`, `isActive`,
  `sendAsPush:bool(false)`, `pushSentAt:?DateTimeImmutable` — when
  `sendAsPush` is checked, the message also triggers an OS push
  notification (in addition to the normal poll queue) once, the first
  time it's saved Active with it on; `pushSentAt` is the guard against
  resending on a later edit. See `PushNotificationService` /
  `ResolveAdminMessageAudienceForPushMessageHandler` in `services.md`.
  `ManyToOne` → `createdBy:?Admin` (`SET NULL`); `ManyToMany` →
  `audienceGroups` (`JoinTable: admin_message_audience_group`);
  `ManyToOne` → `targetClub:?Club` (nullable, `CASCADE`).
- **`MessageDelivery`** (table `message_delivery`) — per-user delivery
  state. `deliveredAt`, `displayedAt`,
  `status:MessageDeliveryStatus(enum, PENDING)`. `ManyToOne` → `User`,
  `AdminMessage` (both not nullable, `CASCADE`).
- **`AudienceGroup`** (table `audience_group`) — a targeting segment.
  `name/slug`, `criteriaType:AudienceCriteriaType(enum, MANUAL)`,
  `criteriaPayload:?array`. Targeted via `AudienceGroupMember` and
  `AdminMessage`'s `ManyToMany`.
- **`AudienceGroupMember`** (table `audience_group_member`) — club↔group
  join. `joinedAt`. `ManyToOne` → `Club`, `group:AudienceGroup` (both not
  nullable, `CASCADE`).
- **`InboxMessage`** — a club's inbox item. `senderType:
  MessageSenderType(enum)`, `senderName/subject/body`, `offerData:?array`,
  `status:MessageStatus(enum, UNREAD)`, `relatedEntityType/Id:?string`,
  `respondedAt`. `ManyToOne` → `Club` (not nullable, `CASCADE`).
- **`GameEventTemplate`** — a random-event definition. `slug`,
  `category:EventCategory(enum)`, `weight`, `title`, `bodyTemplate`,
  `impacts:array`, `firingConditions:?array`, `severity`, `noInteract`,
  `chainedEvents:?array`. No relations. See also
  `docs/event-guide.md`. The `MATCH_NARRATIVE` category (added
  2026-09-19) diverges from the description above: rows are chain-graph
  nodes (slug `{NODE_TYPE}_{N}`, ported verbatim from
  `wunderkind-app`'s `narrativeContent.json`), and `chainedEvents`
  there means a node-type graph edge, not an NPC-interaction weight
  boost — see `MatchNarrativeGeneratorService` in `services.md` and
  `SeedMatchNarrativeTemplatesCommand`.
- **`SocialAccountConnection`** — OAuth connection to a social platform.
  `platform:SocialPlatform(enum)`, `displayName`, `externalAccountId`,
  `accessToken` (encrypted, see `TokenEncryptionService`),
  `refreshToken`, `tokenExpiresAt`, `isActive`, `connectedAt/
  lastRefreshedAt`. No relations.
- **`SocialPostTemplate`** — templated social post copy.
  `category:StatCategory(enum)`, `platform:SocialPlatform(enum)`,
  `period:StatsPeriod(enum)`, `bodyTemplate`, `isActive`. No relations.

## Competition (`App\Entity\Competition`)

- **`CompetitionTemplate`** — a competition's static rules. `name/slug`,
  `entrantCapacity`, `durationOption:CompetitionDuration(enum)`,
  `allowedTiers:?array`, `minClubReputation/minClubAgeSeasons/
  entryFeePerRound/victorPrize`, `roundEngineConfig:?array`, `isActive`.
  `ManyToMany` → `rewardTemplates` (`JoinTable:
  competition_template_reward_template`).
- **`ActiveCompetition`** (table `active_competition`) — one live
  instance of a template. `status:ActiveCompetitionStatus(enum)`,
  `entrantCapacity`, `durationOption:CompetitionDuration(enum)`,
  `registrationOpenedAt`, `lockedAt/startsAt/endsAt/completedAt/
  cancelledAt`, `cancellationReason`. `ManyToOne` → `template:
  CompetitionTemplate` (not nullable, `CASCADE`). Migration
  `Version20260916112510` adds a **partial unique index**
  (`uq_active_competition_one_open_per_template`, `WHERE status =
  'registering'`) — only one open competition per template at a time.
- **`CompetitionEntrant`** (table `competition_entrant`) — a club's
  registration. `seed`, `status:CompetitionEntrantStatus(enum)`,
  `snapshotJson:array`, `snapshotLockedAt`, `snapshotVersion:int(1)`,
  `registeredAt`. `ManyToOne` → `activeCompetition`, `club` (both not
  nullable, `CASCADE`); `eliminatedInRound:?CompetitionRound` (nullable,
  `SET NULL`). Unique index on (`active_competition_id`, `club_id`) —
  one entry per club per competition.
- **`CompetitionRound`** (table `competition_round`) — one round of a
  competition. `roundIndex`, `label`,
  `status:CompetitionRoundStatus(enum)`, `scheduledAt`, `startedAt/
  completedAt`, `matchEngineIdentifier:?MatchEngineIdentifier(enum)`,
  `lockedForProcessingAt`. `ManyToOne` → `activeCompetition` (not
  nullable, `CASCADE`).
- **`CompetitionFixture`** (table `competition_fixture`) — one matchup
  within a round. `slotIndex`, `status:CompetitionFixtureStatus(enum)`,
  `processedAt`. `ManyToOne` → `round` (not nullable, `CASCADE`);
  `homeEntrant/awayEntrant/winnerEntrant:?CompetitionEntrant` (all
  nullable, `SET NULL`).
- **`CompetitionResult`** (table `competition_result`) — a fixture's
  outcome. `homeScore/awayScore` (the true final score, including extra
  time if played — never affected by a shootout), `eventLogJson:array`,
  `homeClubJson/awayClubJson:array` (denormalized club display data —
  kit colours, badge, stadium — copied from the entrant snapshot so a
  client can render kits without a second lookup),
  `homeLineupJson/awayLineupJson:array` (starting XI with
  goals/assists/cards/ratings, admin-only), `narrativePayload:?array`
  (full generated commentary timeline, see
  `MatchNarrativeGeneratorService` in `services.md`),
  `wentToExtraTime/wentToPenalties:bool(false)`,
  `penaltyHomeScore/penaltyAwayScore:?int` (a knockout fixture can never
  end level — these record how a tie was actually settled; the shootout
  winner is a literal coin flip, see `DeterministicEngine`),
  `engineIdentifier:MatchEngineIdentifier(enum)`, `generatedAt`.
  `OneToOne` → `fixture:CompetitionFixture` (not nullable, unique,
  `CASCADE`) — one result per fixture.
- **`RewardTemplate`** (table `reward_template`) — a claimable reward
  definition. `slug/name`, `description`, `effects:array`, `isActive`.
  Reverse side of `CompetitionTemplate`'s `ManyToMany`; referenced by
  `EntrantRewardClaim`.
- **`EntrantRewardClaim`** (table `entrant_reward_claim`) — a claimed
  reward instance. `triggerContext`, `appliedEffectsJson:array`,
  `claimedAt`. `ManyToOne` → `entrant` (not nullable, `CASCADE`),
  `rewardTemplate:?RewardTemplate` (nullable, `SET NULL`).

## Config / singleton "tuning" entities

Each is effectively a single global-config row.

- **`GameConfig`** — very large singleton (~100+ scalar/array fields)
  covering clique, bond/morale, injury, coaching, facility, transfer,
  sponsor/investor, cooldown, squad-role, and social-post tuning
  constants. Notable array fields: `bankruptcyDeductionTiers`,
  `maxSponsorsByTier`, `maxInvestorsByTier`, `npcClubBalanceRanges`,
  `npcFacilityLevelRanges`, `npcClubSizeWeights`, `npcSquadConfig`,
  `squadRoleAppearanceExpectations/MoraleDecayPerWeek/
  MoraleBoostPerWeek/AutoAssignThresholds`, `leaguePlayerAbilityRanges`,
  `wageMultiplierTiers`, `leagueWinPoints`, `pyramidNewsConfig`,
  `statPostRotation/Schedule/LastRunAt`. Fetched/created via
  `GameConfigRepository::getConfig()`. No relations.
- **`PoolConfig`** — singleton tuning for recruitment-pool generation
  ranges (player/coach/scout/agent age/ability/height/weight ranges and
  pool targets per role). No relations.
- **`StarterConfig`** — singleton for new-club starter setup (starting
  balance, starter counts of players/coaches/scouts/managers,
  `starterSponsorTier`, `starterClubTier`, `defaultFacilities`,
  `starterReputationTier`, `enabledCountries`, `leagueAbilityRanges`,
  `npcSquadConfig`, `fanBaseRanges`, promotion/relegation fan-base
  multipliers). `id:int = 1` fixed. No relations.

## Misc / cache / analytics

- **`CountryWorldPackCache`** — persisted (DB-backed, not in-memory)
  cache of generated world-pack payloads. `country`, `tier`,
  `payload:array`, `generatedAt`, `payloadVersion`. No relations. See
  also `state.md`.
- **`LiveTelemetrySnapshot`** — singleton-style live-activity feed row
  (per `LiveTelemetrySnapshotRepository::getSnapshot()`).
  `fixturesSimulated`, `capitalDeployedPence`, `resultsWins/Draws/
  Losses`, `activeClubs`, `weeksPlayed`, `recentEvents:array`,
  `generatedAt`. Built up incrementally across migrations
  `Version20260915213526` → `Version20260915233540` — see
  `migrations.md`. No relations.

## Not an entity

`src/Entity/Concern/EditableJsonColumnTrait.php` — a plain PHP trait
(not `#[ORM\Entity]`), providing `decodeJsonInput`/`invalidJsonInputFor`
helpers for entities with admin-editable JSON columns.
