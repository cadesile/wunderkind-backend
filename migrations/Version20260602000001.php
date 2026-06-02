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
        $this->addSql("ALTER TABLE game_config ADD COLUMN pyramid_news_config JSONB NOT NULL DEFAULT '{\"financial_turmoil\":{\"usedCacheClearAfterEditions\":5},\"outperforming_critics\":{\"usedCacheClearAfterEditions\":3}}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN pyramid_news_frequency_weeks');
        $this->addSql('ALTER TABLE game_config DROP COLUMN pyramid_news_config');
    }
}
