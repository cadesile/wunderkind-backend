# Architecture Notes

- Domain/world-simulation layer separated from generic auth/platform entities — GameConfig, GameEventTemplate, FacilityTemplate, StarterConfig, PoolConfig, TacticalAdvantage, PlayerArchetype model simulation rules, distinct from Admin, Guardian, RefreshToken, EmailVerification which handle account/auth concerns.
- Service layer separating business logic from controllers — ClubInitializationService, WorldInitializationService, PlayerGenerationService, NpcClubGenerationService, StarterPackService, EconomicService, FixtureGenerationService in src/Service, called from src/Controller and src/Controller/Api.
- Import/export facade services for config and content — ConfigImportExportService, LeagueImportExportService, NarrativeImportExportService form a distinct family for serializing/deserializing domain data.
- Caching layer for expensive generated/world data — CountryWorldPackCache and WorldPackCacheService pair an entity with a dedicated service to manage cached "world pack" data.
- Custom Doctrine extensions — src/Doctrine and src/Doctrine/Function directories indicate custom DQL functions or Doctrine type/behavior extensions beyond the standard ORM setup.
