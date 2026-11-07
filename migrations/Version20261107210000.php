<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make the BIG / MEDIUM / SMALL city-size split operator-tunable.
 *
 * These were BIG_SHARE = 0.20 and SMALL_SHARE = 0.50, constants on
 * LocationClassificationService, so the band an operator cared about could only move by a
 * deploy. The split is a judgement about how much football a country should hold, not a fact
 * about the code, and it belongs on the same row as the plot counts it feeds.
 *
 * PERCENTAGES, NOT FRACTIONS, because the admin form edits whole numbers and a column holding
 * 0.2 would need rounding on the way in and out for no benefit. The service divides by 100.
 *
 * MEDIUM IS NOT STORED. It is whatever the two leave in the middle; three numbers that must
 * sum to 100 is two numbers and a subtraction, and a stored third only creates something that
 * can disagree with the other two.
 *
 * DEFAULTS MATCH THE OLD CONSTANTS, so an existing install reclassifies to exactly the bands
 * it already had until somebody deliberately changes them.
 */
final class Version20261107210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the configurable BIG/SMALL city-size shares to location_config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE location_config ADD big_share_percent SMALLINT DEFAULT 20 NOT NULL');
        $this->addSql('ALTER TABLE location_config ADD small_share_percent SMALLINT DEFAULT 50 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE location_config DROP big_share_percent');
        $this->addSql('ALTER TABLE location_config DROP small_share_percent');
    }
}
