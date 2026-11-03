<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration A of the location / land-plot economy: the geography tables.
 *
 * `country_profile`, `location`, `location_rival`, `land_plot`. Claims, holds, stadium
 * estimates and the ledger changes are later migrations — this one adds no behaviour, only
 * somewhere for `app:locations:seed` to put the 865 town records currently hardcoded in
 * CountryContentRegistry::PLACE_NAMES_BY_COUNTRY.
 *
 * Four decisions worth knowing, each explained at length on the entity it belongs to:
 *
 * - `country_profile`'s primary key is the natural key `code VARCHAR(2)`, equal to
 *   App\Enum\Country::$value. That is what lets location.country_code be a real FK rather
 *   than a bare string. **No FK is added to the five existing country columns** (club,
 *   league, npc_club, season_snapshot, country_world_pack_cache) — they are nullable,
 *   device-mirrored, and already validated in application code.
 *
 * - `location.lat`, `lng` and `land_pence` are NULLABLE, not zero-defaulted. The seed only
 *   knows what the PHP consts held (town, region, population, is_capital); coordinates and
 *   valuations arrive later by AI enrichment. Defaulting coordinates to 0/0 would put every
 *   un-enriched town at one point in the Atlantic and the rival pass would rank them as each
 *   other's nearest neighbours.
 *
 * - `location.city_size` is persisted rather than computed, because the BIG/MEDIUM/SMALL
 *   split is a property of the whole country's place set and NpcClub already stores its own
 *   copy. See Location's docblock.
 *
 * - The two CHECK constraints on `location_rival` are **raw SQL here and nowhere else**:
 *   Doctrine has no CHECK mapping, and unlike a partial unique index that is safe —
 *   migrations:diff ignores CHECKs entirely, so they cause no churn. They are what make
 *   "nearest four, and never itself" a database guarantee instead of a convention.
 *
 * Everything else is the plain Schema-API translation of the ORM mapping; verified with
 * `doctrine:schema:update --dump-sql` reporting these four tables and nothing else before
 * this file was written.
 */
final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Location geography: country_profile, location, location_rival, land_plot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE country_profile (
                code VARCHAR(2) NOT NULL,
                name VARCHAR(100) NOT NULL,
                iso_alpha3 VARCHAR(3) DEFAULT NULL,
                continent VARCHAR(30) DEFAULT NULL,
                default_timezone VARCHAR(64) DEFAULT NULL,
                currency_code VARCHAR(3) DEFAULT NULL,
                land_price_currency_code VARCHAR(3) DEFAULT NULL,
                default_locale VARCHAR(10) NOT NULL,
                distance_unit VARCHAR(4) NOT NULL,
                status VARCHAR(16) NOT NULL,
                bbox JSON DEFAULT NULL,
                land_pence_min BIGINT DEFAULT NULL,
                land_pence_max BIGINT DEFAULT NULL,
                tier_structure_ready_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                locations_live_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (code)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_country_profile_status ON country_profile (status)');

        $this->addSql(<<<'SQL'
            CREATE TABLE location (
                id UUID NOT NULL,
                country_code VARCHAR(2) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                town VARCHAR(120) NOT NULL,
                town_normalised VARCHAR(120) NOT NULL,
                localised_names JSON DEFAULT NULL,
                region VARCHAR(120) NOT NULL,
                lat NUMERIC(9, 6) DEFAULT NULL,
                lng NUMERIC(9, 6) DEFAULT NULL,
                is_coordinates_locked BOOLEAN NOT NULL,
                population INT DEFAULT NULL,
                population_source VARCHAR(120) NOT NULL,
                population_as_of DATE DEFAULT NULL,
                is_population_locked BOOLEAN NOT NULL,
                land_pence BIGINT DEFAULT NULL,
                land_valued_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                land_source VARCHAR(160) NOT NULL,
                is_land_locked BOOLEAN NOT NULL,
                is_capital BOOLEAN NOT NULL,
                city_size VARCHAR(10) NOT NULL,
                status VARCHAR(10) NOT NULL,
                rivals_computed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                enrichment_status VARCHAR(16) NOT NULL,
                enrichment_claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                enriched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                enrichment_provider VARCHAR(40) DEFAULT NULL,
                enrichment_model VARCHAR(60) DEFAULT NULL,
                enrichment_attempts SMALLINT NOT NULL,
                enrichment_error VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_location_country_slug ON location (country_code, slug)');
        // Serves the list endpoint's required country filter, its status default, the
        // maxLandPence filter and the default land-price sort, from one index.
        $this->addSql('CREATE INDEX idx_location_country_status_land ON location (country_code, status, land_pence)');
        $this->addSql('CREATE INDEX idx_location_country_region ON location (country_code, region)');
        // NOT unique: two real towns in one country can share a name.
        $this->addSql('CREATE INDEX idx_location_country_town_norm ON location (country_code, town_normalised)');
        $this->addSql('CREATE INDEX idx_location_country_city_size ON location (country_code, city_size)');
        // The no-PostGIS bounding-box prefilter. postgres:16-alpine in both compose files.
        $this->addSql('CREATE INDEX idx_location_lat_lng ON location (lat, lng)');
        // Admin enrichment overview counts, and "claim the next N pending".
        $this->addSql('CREATE INDEX idx_location_enrichment ON location (enrichment_status, enrichment_claimed_at)');
        $this->addSql('CREATE INDEX IDX_5E9E89CBF026BB7C ON location (country_code)');

        $this->addSql(<<<'SQL'
            CREATE TABLE location_rival (
                id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                location_id UUID NOT NULL,
                rival_location_id UUID NOT NULL,
                rank_position SMALLINT NOT NULL,
                distance_km NUMERIC(7, 2) NOT NULL,
                computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_location_rival_rank ON location_rival (location_id, rank_position)');
        $this->addSql('CREATE UNIQUE INDEX uq_location_rival_pair ON location_rival (location_id, rival_location_id)');
        // Reverse lookup ("which locations list me" — rivalry is directional), and FK-delete perf.
        $this->addSql('CREATE INDEX idx_location_rival_rival ON location_rival (rival_location_id)');
        $this->addSql('CREATE INDEX IDX_1DDCDA6964D218E ON location_rival (location_id)');

        // Doctrine cannot map CHECK constraints, so these live only here. Safe, because
        // migrations:diff ignores CHECKs — see the class docblock.
        $this->addSql('ALTER TABLE location_rival ADD CONSTRAINT ck_location_rival_rank CHECK (rank_position BETWEEN 1 AND 4)');
        $this->addSql('ALTER TABLE location_rival ADD CONSTRAINT ck_location_rival_not_self CHECK (location_id <> rival_location_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE land_plot (
                id UUID NOT NULL,
                location_id UUID NOT NULL,
                plot_number SMALLINT NOT NULL,
                label VARCHAR(60) DEFAULT NULL,
                land_pence_override BIGINT DEFAULT NULL,
                status VARCHAR(10) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_land_plot_location_number ON land_plot (location_id, plot_number)');
        $this->addSql('CREATE INDEX idx_land_plot_location_status ON land_plot (location_id, status)');
        $this->addSql('CREATE INDEX IDX_2D4FB44064D218E ON land_plot (location_id)');

        // RESTRICT on location -> country_profile and land_plot -> location: both carry (or
        // will carry) claims and real money, so a delete must fail loudly rather than
        // cascade. Retiring is the supported path — LocationStatus/LandPlotStatus::RETIRED.
        $this->addSql('ALTER TABLE location ADD CONSTRAINT FK_5E9E89CBF026BB7C FOREIGN KEY (country_code) REFERENCES country_profile (code) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE land_plot ADD CONSTRAINT FK_2D4FB44064D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE RESTRICT NOT DEFERRABLE');
        // CASCADE: a rival row is derived data, meaningless without either endpoint. The next
        // app:locations:rivals pass refills any gap a delete leaves.
        $this->addSql('ALTER TABLE location_rival ADD CONSTRAINT FK_1DDCDA6964D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE location_rival ADD CONSTRAINT FK_1DDCDA691F457AEB FOREIGN KEY (rival_location_id) REFERENCES location (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // FKs before tables, per the house convention.
        $this->addSql('ALTER TABLE location_rival DROP CONSTRAINT FK_1DDCDA691F457AEB');
        $this->addSql('ALTER TABLE location_rival DROP CONSTRAINT FK_1DDCDA6964D218E');
        $this->addSql('ALTER TABLE land_plot DROP CONSTRAINT FK_2D4FB44064D218E');
        $this->addSql('ALTER TABLE location DROP CONSTRAINT FK_5E9E89CBF026BB7C');

        $this->addSql('DROP TABLE land_plot');
        $this->addSql('DROP TABLE location_rival');
        $this->addSql('DROP TABLE location');
        $this->addSql('DROP TABLE country_profile');
    }
}
