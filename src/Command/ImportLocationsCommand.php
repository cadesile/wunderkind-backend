<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Location\LocationImportExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read an export back, so enrichment that was paid for once is not paid for again.
 *
 * AN UPSERT, NEVER A REPLACE. Rows are matched on (countryCode, slug) and updated in place;
 * nothing is deleted, and there is no --clear. location is referenced by plot_claim with ON
 * DELETE RESTRICT precisely because a town can carry real money, so a truncate-then-import is
 * either refused by the database or means deleting somebody's purchase first.
 *
 * ADMIN LOCKS IN THE TARGET WIN. A field a human has locked here is skipped and counted, not
 * overwritten — importing geography must not undo the review work the export exists to carry.
 *
 * Always worth a --dry-run first: it reports the exact counts a real run would produce and
 * writes nothing.
 */
#[AsCommand(
    name: 'app:locations:import',
    description: 'Import country profiles and enriched locations from a JSON export.',
)]
class ImportLocationsCommand extends Command
{
    public function __construct(private readonly LocationImportExportService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to a JSON file from app:locations:export')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $file   = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('%s is not a readable file.', $file));

            return Command::FAILURE;
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            $io->error(sprintf('Could not read %s.', $file));

            return Command::FAILURE;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $io->error('That file is not valid JSON: ' . json_last_error_msg());

            return Command::FAILURE;
        }

        $result = $this->service->import($data, $dryRun);

        $io->definitionList(
            ['Countries created' => $result['countriesCreated']],
            ['Countries updated' => $result['countriesUpdated']],
            ['Locations created' => $result['locationsCreated']],
            ['Locations updated' => $result['locationsUpdated']],
            ['Plots created'     => $result['plotsCreated']],
            ['Locked fields skipped' => $result['lockedFieldsSkipped']],
        );

        if ($result['lockedFieldsSkipped'] > 0) {
            $io->note(sprintf(
                '%d field group(s) were left alone because they are locked in THIS environment. '
                . 'That is the lock doing its job — a human correction here outranks the file.',
                $result['lockedFieldsSkipped'],
            ));
        }

        foreach ($result['errors'] as $error) {
            $io->warning($error);
        }

        if ($dryRun) {
            $io->note('Dry run — nothing was written.');

            return Command::SUCCESS;
        }

        if (!$result['applied']) {
            $io->error('Nothing was applied. See the errors above.');

            return Command::FAILURE;
        }

        $io->success('Import applied.');
        $io->text(
            'Rivals were invalidated for every row touched — run <info>app:locations:rivals '
            . '--stale-only</info> to rebuild them, and <info>app:plots:refresh-estimates</info> '
            . 'to reprice stadiums against the imported land values.',
        );

        return Command::SUCCESS;
    }
}
