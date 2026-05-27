<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prepopulate trophy_image and trophy_colour on any league row that has neither set.
 * Each league gets a random design (trophy-1 … trophy-15) and a random colour
 * (silver / gold / gold_silver). Rows already configured are left untouched.
 */
final class Version20260527190328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed random trophy_image + trophy_colour for leagues where both are unset';
    }

    public function up(Schema $schema): void
    {
        // ARRAY subscript is 1-based in PostgreSQL.
        // FLOOR(RANDOM() * 15 + 1) gives a uniform integer in [1, 15].
        // FLOOR(RANDOM() * 3  + 1) gives a uniform integer in [1, 3].
        $this->addSql(<<<'SQL'
            UPDATE league
            SET
                trophy_image  = 'trophy-' || FLOOR(RANDOM() * 15 + 1)::int,
                trophy_colour = (ARRAY['silver', 'gold', 'gold_silver'])[FLOOR(RANDOM() * 3 + 1)::int]
            WHERE trophy_image IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Nullifies only rows this migration would have touched.
        // Admin-configured rows already had a value, so the WHERE skipped them
        // and this rollback will not affect them either.
        $this->addSql(<<<'SQL'
            UPDATE league
            SET trophy_image = NULL, trophy_colour = NULL
            WHERE trophy_image LIKE 'trophy-%'
        SQL);
    }
}
