<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Splits the telemetry HUD's Table Turnover figure by direction:
 * `live_telemetry_snapshot` gains `promotions` and `relegations`.
 *
 * The landing page's tile now reads "▲ 7 ▼ 4" rather than "11" — the same rows over the
 * same 7-day window, counted twice by `SeasonRecordRepository::countTurnoverByDirectionSince()`
 * instead of once.
 *
 * `table_turnover` STAYS, and `app:telemetry:generate` keeps writing it. It is published as
 * `stats.tableTurnover` on `GET /api/telemetry`, so dropping it would break a field an app
 * release already in the wild reads. It equals promotions + relegations.
 *
 * Both columns are backfilled from `table_turnover` rather than left at 0: the table holds a
 * single snapshot row, the next `app:telemetry:generate` tick overwrites all three anyway, and
 * a landing page rendered in between would otherwise show ▲0 ▼0 beside a live feed. The split
 * cannot be recovered from the total, so the whole of it goes to `promotions` — wrong in
 * detail for at most one cron interval, where 0/0 would be wrong AND look broken.
 */
final class Version20261102160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'live_telemetry_snapshot gains promotions + relegations, the Table Turnover tile split by direction.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('live_telemetry_snapshot');

        $table->addColumn('promotions', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('relegations', 'integer', ['notnull' => true, 'default' => 0]);
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement('UPDATE live_telemetry_snapshot SET promotions = table_turnover');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('live_telemetry_snapshot');

        $table->dropColumn('promotions');
        $table->dropColumn('relegations');
    }
}
