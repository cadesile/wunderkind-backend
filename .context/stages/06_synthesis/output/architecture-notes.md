# Architecture Notes

- **Import/Export services** — ConfigImportExportService, LeagueImportExportService, NarrativeImportExportService in src/Service handle serialization of domain data (config, leagues, narrative) as a distinct concern from the entities themselves.

- **Template + generator pairing** — FacilityTemplate, GameEventTemplate, SocialPostTemplate entities are paired with generator/renderer services (NpcClubGenerationService, PlayerGenerationService, NameGeneratorService, AppearanceGeneratorService, SocialPostRenderer) that instantiate content from templates.

- **Appearance subsystem isolation** — src/Service/Appearance and src/Enum/Appearance directories, plus AppearanceGeneratorService and FacilityImageResolver, group appearance-related logic separately from the general Service/Enum namespaces.

- **API resource/entity separation** — src/ApiResource and src/Dto (with src/Dto/Leaderboard) exist alongside src/Entity, separating API-facing representations (e.g. LeaderboardEntry-related DTOs) from persistence entities.

- **Admin-scoped controllers** — src/Controller/Admin is split out from src/Controller, and an Admin entity exists separately from User, indicating a distinct administrative access layer.
