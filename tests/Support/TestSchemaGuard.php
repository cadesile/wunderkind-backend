<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Refuse to start a run when `wunderkind_test` does not match the entity mapping.
 *
 * WHY THIS EXISTS, and it is the most expensive recurring mistake on this project:
 * `wunderkind_test` is built by `doctrine:schema:create` from entity metadata and is NOT
 * migration-tracked, so a migration someone else wrote never reaches it. The suite then fails
 * in bulk — six separate sessions between 2026-11-02 and 2026-11-25 lost time to it, four of
 * them reporting the SAME pair of numbers, 192 errors and 89 failures, because nearly every
 * functional test loads a GameConfig and one missing column on that table fails all of them.
 * The failures look nothing like a missing column; they look like the change in flight broke
 * everything, which is exactly why they get mis-attributed.
 *
 * `.context/stages/01_overview/output/environment.md` has said "run
 * `schema:update --dump-sql --env=test` BEFORE reading a single assertion" for weeks. Six
 * occurrences says documentation is the wrong instrument: nobody reads a warning they do not
 * yet know they need. So the check runs itself, once per suite, and spends about a second of a
 * five-minute run to replace a wall of red with the three lines that fix it.
 *
 * DELIBERATELY NOT A TEST. `SchemaIsInSyncTest` already asserts the same condition and is the
 * right place for the assertion, but a test reports at the END of the run, interleaved with the
 * 200 failures it explains and only after five minutes have been spent. Running in bootstrap
 * means the run never starts.
 *
 * It never fails a run for its own reasons: if the database cannot be reached, or anything in
 * the check throws, it says so in one line and lets the suite proceed. `WK_SKIP_SCHEMA_CHECK=1`
 * turns it off outright, for the deliberate "I know, run it anyway" case — and note it has to be
 * set INSIDE the container, because `lando php` does not forward the host's environment:
 *
 *     lando ssh -s appserver -c 'cd /app && WK_SKIP_SCHEMA_CHECK=1 php vendor/bin/phpunit'
 */
final class TestSchemaGuard
{
    /** How many outstanding statements to print before summarising the rest. */
    private const MAX_SHOWN = 15;

    public static function run(): void
    {
        if (self::skipRequested()) {
            return;
        }

        $kernel = null;

        try {
            $kernel = new Kernel('test', (bool) ($_SERVER['APP_DEBUG'] ?? false));
            $kernel->boot();

            $container = $kernel->getContainer();
            if ($container->has('test.service_container')) {
                $container = $container->get('test.service_container');
            }

            /** @var EntityManagerInterface $em */
            $em = $container->get(EntityManagerInterface::class);

            // Touch the connection on its own first, so "no database" is a skip with a readable
            // reason rather than a failure from somewhere inside the schema comparator.
            $em->getConnection()->executeQuery('SELECT 1');

            $outstanding = TestSchemaDrift::outstandingSql($em, $container);
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "note: test-database schema check skipped (%s)\n\n",
                $e->getMessage(),
            ));

            return;
        } finally {
            $kernel?->shutdown();
        }

        if ($outstanding === []) {
            return;
        }

        fwrite(STDERR, self::report($outstanding));
        exit(1);
    }

    /** Honours the flag from the real environment or from PHPUnit's own `<env>`/`<server>` config. */
    private static function skipRequested(): bool
    {
        foreach ([getenv('WK_SKIP_SCHEMA_CHECK'), $_SERVER['WK_SKIP_SCHEMA_CHECK'] ?? null, $_ENV['WK_SKIP_SCHEMA_CHECK'] ?? null] as $value) {
            if ($value !== false && $value !== null && filter_var((string) $value, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $outstanding */
    private static function report(array $outstanding): string
    {
        $shown  = \array_slice($outstanding, 0, self::MAX_SHOWN);
        $hidden = \count($outstanding) - \count($shown);

        $lines = [
            '',
            'STOPPED BEFORE RUNNING: wunderkind_test does not match the entity mapping.',
            '',
            sprintf(
                '%d outstanding statement%s — the database is behind the entities, which fails',
                \count($outstanding),
                \count($outstanding) === 1 ? '' : 's',
            ),
            'tests in bulk for reasons that look like a broken change. It is not your change.',
            '',
        ];

        foreach ($shown as $statement) {
            $lines[] = '    ' . $statement;
        }

        if ($hidden > 0) {
            $lines[] = sprintf('    ... and %d more', $hidden);
        }

        $lines[] = '';
        $lines[] = 'Fix it with:';
        $lines[] = '';
        $lines[] = '    lando php bin/console doctrine:schema:update --force --env=test';
        $lines[] = '';
        $lines[] = 'If that aborts on a column that "contains null values", run the owning';
        $lines[] = "migration's own ALTER instead — it carries the default that backfills the";
        $lines[] = 'existing row. See .context/stages/01_overview/output/environment.md.';
        $lines[] = '';
        $lines[] = 'To run anyway (the variable must be set INSIDE the container — `lando php`';
        $lines[] = "does not forward the host's environment):";
        $lines[] = '';
        $lines[] = "    lando ssh -s appserver -c 'cd /app && WK_SKIP_SCHEMA_CHECK=1 php vendor/bin/phpunit'";
        $lines[] = '';

        return implode("\n", $lines);
    }
}
