<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Links a club to the plot it plays on, and makes club names unique per town.
 *
 * TWO COLUMNS AND ONE INDEX, each with a reason.
 *
 * `home_location_id` is the second half of a relationship that has been dangling since the land
 * economy shipped: PlotClaim has carried a nullable club and PlotClaimService::attachClub() has
 * existed, with nothing on the club side to point back and — until now — no caller at all. It is
 * NULLABLE and stays that way: every club founded before this has no plot, and inventing a town
 * for a live save would be worse than leaving the link empty. ON DELETE SET NULL because a
 * location is retired rather than deleted, so this only fires under admin surgery, where losing
 * the link beats blocking the operation.
 *
 * `name_normalised` exists so the uniqueness rule can be a database constraint instead of an
 * application convention. "Brescia AS", "brescia as" and "Bréscia  AS" all render identically
 * in game, and only a normalised column can say so to an index. It is backfilled in postUp()
 * using the SAME rules as App\Service\ClubNameNormalizer — lowercase, strip diacritics, collapse
 * whitespace — expressed in SQL via unaccent-free `translate()`-less means: see the note on the
 * backfill below for why it is done in PHP rather than SQL.
 *
 * `uq_club_name_per_location` is PARTIAL. That is not an optimisation, it is the only thing that
 * makes it correct: home_location_id is NULL for every pre-existing club and every spoof clone,
 * and PostgreSQL treats NULLs as distinct — a plain unique on the pair would appear enforced
 * while permitting unlimited duplicates among exactly those rows. Being partial also means
 * EXISTING duplicate names cannot block this migration, because none of them has a location yet.
 *
 * Scoped PER LOCATION rather than per country on purpose: a club's name is its town plus an
 * affix, so two "Burnley FC"s only clash when both sit at Burnley. Per-country would reject a
 * legitimate second club across forty other towns.
 */
final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'club.home_location_id + club.name_normalised, and a partial unique making club names unique per town.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD home_location_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD name_normalised VARCHAR(100) DEFAULT NULL');

        $this->addSql(
            'ALTER TABLE club ADD CONSTRAINT fk_club_home_location
             FOREIGN KEY (home_location_id) REFERENCES location (id)
             ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE',
        );
        // Doctrine's own generated name for a join-column index, matching the convention in
        // Version20261103220000 — an explicit name here would show up as a rename on every
        // subsequent schema diff.
        $this->addSql('CREATE INDEX IDX_B8EE3872BB811CC3 ON club (home_location_id)');
    }

    /**
     * Backfill, then make the column NOT NULL and build the unique index.
     *
     * IN PHP, NOT SQL, and deliberately. ClubNameNormalizer strips diacritics through
     * Transliterator (or iconv), and reproducing that in SQL would need the `unaccent`
     * extension — a new superuser-installed extension on the production image for a one-off
     * backfill, and worse, a SECOND definition of the normalisation rules that could drift from
     * the PHP one. The client already mirrors these rules in src/utils/clubName.ts; a third copy
     * in SQL is exactly the kind of drift the normalizer exists to prevent.
     *
     * The unique index is created AFTER the backfill for the obvious reason, and the NOT NULL
     * after it too, so a club added by a concurrent deploy cannot slip in with a null.
     */
    public function postUp(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, name FROM club');

        foreach ($rows as $row) {
            $this->connection->executeStatement(
                'UPDATE club SET name_normalised = :n WHERE id = :id',
                [
                    'n'  => \App\Service\ClubNameNormalizer::normalize((string) $row['name']),
                    'id' => $row['id'],
                ],
            );
        }

        $this->connection->executeStatement('ALTER TABLE club ALTER name_normalised SET NOT NULL');
        $this->connection->executeStatement('ALTER TABLE club ALTER name_normalised DROP DEFAULT');

        // Partial — see the class docblock. Spelled as pg_get_expr(indpred, indrelid) reports it
        // so migrations:diff stays clean and SchemaIsInSyncTest passes.
        $this->connection->executeStatement(
            'CREATE UNIQUE INDEX uq_club_name_per_location
                 ON club (home_location_id, name_normalised)
              WHERE (home_location_id IS NOT NULL)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uq_club_name_per_location');
        $this->addSql('DROP INDEX IF EXISTS IDX_B8EE3872BB811CC3');
        $this->addSql('ALTER TABLE club DROP CONSTRAINT IF EXISTS fk_club_home_location');
        $this->addSql('ALTER TABLE club DROP COLUMN IF EXISTS home_location_id');
        $this->addSql('ALTER TABLE club DROP COLUMN IF EXISTS name_normalised');
    }
}
