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
 * Write enriched geography to a JSON file, so another environment need not pay for it again.
 *
 * Enrichment is one billed API call per town and a human review pass over every AI-sourced land
 * valuation. Export once, import everywhere — see LocationImportExportService for exactly what
 * travels and what deliberately does not (claims, holds and ledger entries never do).
 *
 * Writes to stdout by default so it composes with a pipe; `--output` writes a file.
 */
#[AsCommand(
    name: 'app:locations:export',
    description: 'Export country profiles and enriched locations to JSON.',
)]
class ExportLocationsCommand extends Command
{
    public function __construct(private readonly LocationImportExportService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'countries',
                InputArgument::IS_ARRAY,
                'Country codes to export (default: all)',
            )
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $countries = $input->getArgument('countries');
        $target    = $input->getOption('output');

        $data = $this->service->export($countries === [] ? null : $countries);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $io->error('Could not encode the export: ' . json_last_error_msg());

            return Command::FAILURE;
        }

        if ($target === null) {
            // Straight to stdout, with no SymfonyStyle decoration, so `> file` and `| jq` both
            // produce valid JSON.
            $output->writeln($json);

            return Command::SUCCESS;
        }

        if (file_put_contents($target, $json) === false) {
            $io->error(sprintf('Could not write %s.', $target));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Exported %d countries and %d locations to %s (%s).',
            count($data['countries']),
            count($data['locations']),
            $target,
            $this->humanBytes(strlen($json)),
        ));

        return Command::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes < 1024 * 1024
            ? sprintf('%.1f KB', $bytes / 1024)
            : sprintf('%.1f MB', $bytes / 1024 / 1024);
    }
}
