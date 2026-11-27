<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the plot-ownership WHAT TO DO note on the new-game LOCATION step (Courtney, 2026-11-27)
 * to the stored setup tutorial, after the country note. Skipped where an item with that id
 * already exists, so an operator's own edit survives. Older app builds drop the note: their
 * tutorial parser does not know the `location` anchor and ignores unknown anchors.
 */
final class Version20261127001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the note-location item to starter_config.setup_tutorial_config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE starter_config
               SET setup_tutorial_config = jsonb_set(
                       setup_tutorial_config::jsonb,
                       '{items}',
                       COALESCE(setup_tutorial_config::jsonb -> 'items', '[]'::jsonb)
                         || '[{"id":"note-location","kind":"note","anchor":{"on":"location"},"bodyKey":"ngs.tut.note.location","dismissible":true}]'::jsonb
                   )::json
             WHERE NOT COALESCE(setup_tutorial_config::jsonb -> 'items', '[]'::jsonb) @> '[{"id":"note-location"}]'::jsonb
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE starter_config
               SET setup_tutorial_config = jsonb_set(
                       setup_tutorial_config::jsonb,
                       '{items}',
                       COALESCE((
                           SELECT jsonb_agg(item)
                             FROM jsonb_array_elements(setup_tutorial_config::jsonb -> 'items') AS item
                            WHERE item ->> 'id' <> 'note-location'
                       ), '[]'::jsonb)
                   )::json
            SQL);
    }
}
