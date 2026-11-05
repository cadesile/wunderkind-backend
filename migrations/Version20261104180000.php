<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enrichment quota: a per-account spend meter and the ceiling it is read against.
 *
 * `POST /api/locations/{id}/enrich` costs one billed external API call per town, and until now
 * nothing bounded how many an account could ask for — ~4,200 towns and climbing.
 *
 * BOTH COLUMNS DEFAULT TO A VALUE THAT PRESERVES TODAY'S BEHAVIOUR FOR EXISTING ROWS, which is
 * why neither needs a backfill:
 *
 * - `user.enrichment_count` starts at 0, so no existing account is retroactively billed for
 *   valuations it already triggered. There is nothing to backfill from in any case: enrichment
 *   attempts were never attributed to a user.
 * - `starter_config.max_enrichment_requests_per_user` defaults to 25 for a NEW config row, but
 *   the UPDATE below sets the single existing row to **0, which means unlimited**. Enrichment is
 *   already deployed and in use on dev; giving the live row a ceiling of 25 as a side effect of
 *   a migration would silently start refusing requests that work today. An operator sets the
 *   real number in the admin, deliberately, and sees it on the form.
 */
final class Version20261104180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.enrichment_count and starter_config.max_enrichment_requests_per_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD enrichment_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE starter_config ADD max_enrichment_requests_per_user INT DEFAULT 25 NOT NULL');

        // The already-deployed endpoint keeps working exactly as it does now until an admin
        // chooses a ceiling. See the class docblock.
        $this->addSql('UPDATE starter_config SET max_enrichment_requests_per_user = 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP enrichment_count');
        $this->addSql('ALTER TABLE starter_config DROP max_enrichment_requests_per_user');
    }
}
