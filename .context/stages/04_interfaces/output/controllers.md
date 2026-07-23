# Controllers

#### `AdminCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `AgentCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `BetaRequestCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFilters(Filters $filters): Filters
    public function configureFields(string $pageName): iterable
```

#### `ClubCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function __construct(private EntityManagerInterface $em) {}
    public function configureActions(Actions $actions): Actions
    public function detail(AdminContext $context): Response
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `DashboardController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function __construct(
    public function index(): Response
    #[Route('/admin/app-links', name: 'admin_app_links')]
    public function appLinks(): Response
    #[Route('/admin/app-links/save', name: 'admin_app_links_save', methods: ['POST'])]
    public function saveAppLinks(Request $request): Response
    #[Route('/admin/social', name: 'admin_social_connections')]
    public function socialConnections(): Response
    #[Route('/admin/social/test/preview', name: 'admin_social_test_preview', methods: ['POST'])]
    public function socialTestPreview(Request $request, \App\Service\SocialPostRenderer $renderer): Response
    #[Route('/admin/social/test/publish', name: 'admin_social_test_publish', methods: ['POST'])]
    public function socialTestPublish(Request $request, \App\Service\SocialPostingService $postingService): Response
    #[Route('/admin/settings', name: 'admin_settings')]
    public function settings(): Response
    #[Route('/admin/game-config', name: 'admin_game_config')]
    public function gameConfig(): Response
    #[Route('/admin/game-config/save', name: 'admin_game_config_save', methods: ['POST'])]
    public function saveGameConfig(Request $request): Response
    #[Route('/admin/starter-config', name: 'admin_starter_config')]
    public function starterConfig(): Response
```

#### `DeleteAdminController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function __construct(
    #[Route('/admin/clubs/{id}/delete-info', name: 'admin_club_delete_info', methods: ['GET'])]
    public function clubDeleteInfo(Club $club): Response
    #[Route('/admin/clubs/{id}/delete', name: 'admin_club_delete', methods: ['POST'])]
    public function clubDelete(Request $request, Club $club): Response
    #[Route('/admin/users/{id}/delete-info', name: 'admin_user_delete_info', methods: ['GET'])]
    public function userDeleteInfo(User $user): Response
    #[Route('/admin/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function userDelete(Request $request, User $user): Response
```

#### `FacilityAdminController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function __construct(private EntityManagerInterface $em) {}
    #[Route('/admin/facilities/{id}/quick-edit', name: 'admin_facility_quick_edit', methods: ['POST'])]
    public function quickEdit(Request $request, FacilityTemplate $facility): Response
```

#### `FacilityTemplateCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `GameEventTemplateCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `GuardianCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `InvestorCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `LeaderboardEntryCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `LeagueAdminController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function __construct(private EntityManagerInterface $em) {}
    #[Route('/admin/leagues/{id}/quick-edit', name: 'admin_league_quick_edit', methods: ['POST'])]
    public function quickEdit(Request $request, League $league): Response
```

#### `LeagueCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureCrud(Crud $crud): Crud
    public function configureFilters(Filters $filters): Filters
    public function configureFields(string $pageName): iterable
```

#### `NpcClubCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `PlayerArchetypeCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `PlayerCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function __construct(
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function index(AdminContext $context): KeyValueStore|Response
    public function configureFilters(Filters $filters): Filters
    public function configureFields(string $pageName): iterable
```

#### `ScoutCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `SocialAuthController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/admin/social')]
    public function __construct(
    #[Route('/{id}/disconnect', name: 'admin_social_disconnect', methods: ['POST'])]
    public function disconnect(Request $request, string $id): Response
    #[Route('/facebook/connect', name: 'admin_social_facebook_connect', methods: ['GET'])]
    public function facebookConnect(Request $request): RedirectResponse
    #[Route('/facebook/callback', name: 'admin_social_facebook_callback', methods: ['GET'])]
    public function facebookCallback(Request $request): Response
    #[Route('/twitter/connect', name: 'admin_social_twitter_connect', methods: ['GET'])]
    public function twitterConnect(Request $request): RedirectResponse
    #[Route('/twitter/callback', name: 'admin_social_twitter_callback', methods: ['GET'])]
    public function twitterCallback(Request $request): Response
```

#### `SocialPostTemplateCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `SponsorCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `StaffCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFilters(Filters $filters): Filters
    public function configureFields(string $pageName): iterable
```

#### `SyncRecordCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `TacticalAdvantageCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureFields(string $pageName): iterable
```

#### `TransferCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### `UserCrudController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
    public function detail(AdminContext $context): Response
```

#### `WorldPackController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
public function __construct(
    #[Route('/admin/worldpack-cache/delete/{id}', name: 'admin_worldpack_delete_entry', methods: ['POST'])]
    public function deleteEntry(string $id, Request $request): Response
    #[Route('/admin/worldpack-cache/delete-country', name: 'admin_worldpack_delete_country', methods: ['POST'])]
    public function deleteCountry(Request $request): Response
    #[Route('/admin/worldpack-cache/tiers/{country}', name: 'admin_worldpack_tiers', methods: ['GET'])]
    public function getTiers(string $country): JsonResponse
    #[Route('/admin/worldpack-cache/warm-tier', name: 'admin_worldpack_warm_tier', methods: ['POST'])]
    public function warmTier(Request $request): JsonResponse
```

#### `AdminSecurityController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/admin/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(): never
```

#### `AccountController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/account')]
    #[Route('/delete', name: 'api_account_delete', methods: ['POST'])]
    public function delete(AccountDeletionService $accountDeletionService, LoggerInterface $logger): JsonResponse
```

#### `AdminController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/admin')]
    #[Route('/stats', name: 'api_admin_stats', methods: ['GET'])]
    public function stats(): JsonResponse
```

#### `AppLinksController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api')]
    public function __construct(
    #[Route('/app-links', name: 'api_app_links', methods: ['GET'])]
    public function index(): JsonResponse
```

#### `ArchetypeController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/archetypes', name: 'api_archetypes', methods: ['GET'])]
    public function __construct(
    public function __invoke(): JsonResponse
```

#### `BetaRequestController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api')]
    public function __construct(
    #[Route('/beta-request', name: 'api_beta_request', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    #[Route('/beta-request/verify', name: 'api_beta_request_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
```

#### `ClubController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/club')]
    public function __construct(private readonly ClubRepository $clubRepository) {}
    #[Route('/foreign', name: 'api_clubs_foreign', methods: ['GET'])]
    public function foreignClubs(Request $request, NpcClubRepository $npcClubRepo): JsonResponse
    #[Route('/name-options', name: 'api_clubs_name_options', methods: ['GET'])]
    public function nameOptions(Request $request, NpcClubGenerationService $npcClubGenerationService): JsonResponse
    #[Route('/initialize', name: 'api_club_initialize', methods: ['POST'])]
    public function initialize(
    #[Route('/check', name: 'api_club_check', methods: ['GET'])]
    public function check(): JsonResponse
    #[Route('/status', name: 'api_club_status', methods: ['GET'])]
    public function status(): JsonResponse
```

#### `CommunityStatsController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/stats')]
    public function __construct(
    #[Route('/most-transfers', name: 'api_stats_most_transfers', methods: ['GET'])]
    public function mostTransfers(Request $request): JsonResponse
    #[Route('/most-development', name: 'api_stats_most_development', methods: ['GET'])]
    public function mostDevelopment(Request $request): JsonResponse
    #[Route('/most-seasons', name: 'api_stats_most_seasons', methods: ['GET'])]
    public function mostSeasons(Request $request): JsonResponse
    #[Route('/most-trophies', name: 'api_stats_most_trophies', methods: ['GET'])]
    public function mostTrophies(Request $request): JsonResponse
```

#### `EventController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/events')]
    public function __construct(
    #[Route('/templates', name: 'api_events_templates', methods: ['GET'])]
    public function templates(): JsonResponse
```

#### `FinanceController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/finance')]
    public function __construct(
    #[Route('/overview', methods: ['GET'])]
    public function overview(): JsonResponse
    #[Route('/investors', methods: ['GET'])]
    public function investors(): JsonResponse
    #[Route('/sponsors', methods: ['GET'])]
    public function sponsors(): JsonResponse
    #[Route('/sponsors/{id}/terminate', methods: ['POST'])]
    public function terminateSponsor(string $id): JsonResponse
```

#### `GameConfigController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api')]
    public function __construct(
    #[Route('/game-config', name: 'api_game_config', methods: ['GET'])]
    public function index(): JsonResponse
```

#### `InboxController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/inbox')]
    public function __construct(
    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    #[Route('/{id}/accept', methods: ['POST'])]
    public function accept(string $id): JsonResponse
    #[Route('/{id}/reject', methods: ['POST'])]
    public function reject(string $id): JsonResponse
    #[Route('/{id}/read', methods: ['POST'])]
    public function markRead(string $id): JsonResponse
```

#### `LeagueController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/league')]
    public function __construct(
    #[Route('/conclude-season', name: 'api_league_conclude_season', methods: ['POST'])]
    public function concludeSeason(#[MapRequestPayload] ConcludeSeasonRequest $dto): JsonResponse
    #[Route('/season-history', name: 'api_league_season_history', methods: ['GET'])]
    public function seasonHistory(): JsonResponse
    #[Route('/season-history/{season}', name: 'api_league_season_history_detail', methods: ['GET'])]
    public function seasonHistoryDetail(int $season): JsonResponse
```

#### `MarketController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/market')]
    #[Route('/data', name: 'api_market_pool_data', methods: ['GET'])]
    public function data(Request $request, MarketDataService $service): JsonResponse
    #[Route('/assign', name: 'api_market_assign', methods: ['POST'])]
    public function assign(
    #[Route('/consume', name: 'api_market_consume', methods: ['POST'])]
    public function consume(
    #[Route('/legacy', name: 'api_market_data_legacy', methods: ['GET'])]
    public function legacyData(
```

#### `PoolController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/pool')]
    #[Route('/ensure', name: 'api_pool_ensure', methods: ['POST'])]
    public function ensure(
```

#### `ScoutSearchController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/scout')]
    #[Route('/foreign-clubs', name: 'api_scout_foreign_clubs', methods: ['GET'])]
    public function foreignClubs(Request $request, NpcClubRepository $npcClubRepo): JsonResponse
    #[Route('/search', name: 'api_scout_search', methods: ['GET'])]
    public function search(Request $request, PlayerRepository $playerRepo): JsonResponse
```

#### `StarterConfigController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api')]
    public function __construct(
    #[Route('/starter-config', name: 'api_starter_config', methods: ['GET'])]
    public function index(): JsonResponse
```

#### `TransferLeaderboardController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/leaderboard/transfers')]
    public function __construct(
    #[Route('/top-sellers', name: 'api_transfer_leaderboard_top_sellers', methods: ['GET'])]
    public function topSellers(Request $request): JsonResponse
    #[Route('/most-valuable', name: 'api_transfer_leaderboard_most_valuable', methods: ['GET'])]
    public function mostValuable(Request $request): JsonResponse
```

#### `HomeController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/', name: 'home', methods: ['GET'])]
    public function index(): BinaryFileResponse
```

#### `InitializeController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api/initialize')]
    public function __construct(
    #[Route('/starter', name: 'api_initialize_starter', methods: ['POST'])]
    public function starter(Request $request): JsonResponse
    #[Route('/leagues', name: 'api_initialize_leagues', methods: ['GET'])]
    public function leagues(): JsonResponse
    #[Route('/league/{tier}', name: 'api_initialize_league_tier', requirements: ['tier' => '\d+'], methods: ['POST'])]
    public function tier(int $tier): JsonResponse
```

#### `LeaderboardController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api')]
    #[Route('/leaderboard/{category}', name: 'api_leaderboard', methods: ['GET'])]
    public function index(
```

#### `SyncController`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
#[Route('/api')]
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
    #[Route('/verify-email', name: 'api_verify_email', methods: ['POST'])]
    public function verifyEmail(
    #[Route('/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function resendVerification(
    #[Route('/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgotPassword(
    #[Route('/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function resetPassword(
    #[Route('/resend-password-reset', name: 'api_resend_password_reset', methods: ['POST'])]
    public function resendPasswordReset(
    #[Route('/sync', name: 'api_sync', methods: ['POST'])]
    public function sync(
```
