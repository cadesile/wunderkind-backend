<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convert squad_role_appearance_expectations from absolute match counts to
 * percentage values (0.0–1.0) so the frontend can scale to any season length.
 */
final class Version20260529000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert squad_role_appearance_expectations from absolute counts to 0.0–1.0 percentages';
    }

    public function up(Schema $schema): void
    {
        $newDefault = '{"star_player":0.9,"important":0.75,"first_team":0.6,"squad_player":0.4,"backup":0.2,"prospect":0.0}';

        $this->addSql(<<<SQL
            UPDATE game_config
            SET squad_role_appearance_expectations = '{$newDefault}'::json
            WHERE squad_role_appearance_expectations IS NOT NULL
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE game_config
                ALTER COLUMN squad_role_appearance_expectations
                SET DEFAULT '{$newDefault}'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $oldDefault = '{"star_player":30,"important":25,"first_team":20,"squad_player":12,"backup":5,"prospect":0}';

        $this->addSql(<<<SQL
            UPDATE game_config
            SET squad_role_appearance_expectations = '{$oldDefault}'::json
            WHERE squad_role_appearance_expectations IS NOT NULL
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE game_config
                ALTER COLUMN squad_role_appearance_expectations
                SET DEFAULT '{$oldDefault}'
        SQL);
    }
}
