<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pyramid_news_frequency_weeks and pyramid_news_config to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD COLUMN pyramid_news_frequency_weeks INT NOT NULL DEFAULT 4");
        $this->addSql("ALTER TABLE game_config ADD COLUMN pyramid_news_config JSONB NOT NULL DEFAULT '{\"financial_turmoil\":{\"usedCacheClearAfterEditions\":5},\"top_goalscorer\":{\"usedCacheClearAfterEditions\":10},\"top_assists\":{\"usedCacheClearAfterEditions\":10},\"most_on_form_player\":{\"usedCacheClearAfterEditions\":10},\"best_game_most_goals\":{\"usedCacheClearAfterEditions\":10},\"best_game_biggest_win\":{\"usedCacheClearAfterEditions\":10},\"biggest_upset\":{\"usedCacheClearAfterEditions\":5},\"on_the_rise\":{\"usedCacheClearAfterEditions\":5},\"outperforming_critics\":{\"usedCacheClearAfterEditions\":3},\"season_predictions\":{\"usedCacheClearAfterEditions\":20}}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN pyramid_news_frequency_weeks');
        $this->addSql('ALTER TABLE game_config DROP COLUMN pyramid_news_config');
    }
}
