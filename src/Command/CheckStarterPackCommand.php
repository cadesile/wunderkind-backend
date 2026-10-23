<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\StarterPackDiagnosticService;
use App\Service\StarterPackService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only "could a new club in this country be given a starter pack right now?" check.
 * CLI twin of the admin screen (/admin?routeName=admin_starter_pack_check) — both render
 * the same StarterPackDiagnosticService result, so an answer over SSH and an answer in a
 * browser cannot disagree.
 *
 * Exit code is the verdict, so it can gate a deploy step: 0 ok, 1 blocked or degraded.
 */
#[AsCommand(
    name: 'app:starter-pack:check',
    description: 'Report whether the pool can supply a starter pack for a country, and which filter empties the draw.',
)]
class CheckStarterPackCommand extends Command
{
    public function __construct(
        private readonly StarterPackDiagnosticService $diagnostics,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. US, JP)')
            ->addOption(
                'tier',
                null,
                InputOption::VALUE_REQUIRED,
                'League tier to test',
                (string) StarterPackService::DEFAULT_STARTER_TIER,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $result = $this->diagnostics->diagnose(
            (string) $input->getArgument('country'),
            (int) $input->getOption('tier'),
        );

        $io->title(sprintf('Starter pack check: %s (%s), tier %d', $result['country'], $result['countryLabel'], $result['tier']));

        $io->table(['Setting', 'Value'], [
            ['Nationality',       $result['nationality'] ?? '— unresolved —'],
            ['Ability band',      sprintf('%d–%d', $result['ability']['min'], $result['ability']['max'])],
            ['Band source',       $result['ability']['source']],
            ['First-team age',    sprintf('%d+ (born on or before %s)', $result['firstTeamAgeMin'], $result['bornOnOrBefore']->format('Y-m-d'))],
            ['Pool size',         sprintf('%d players', $result['poolTotal'])],
            ['Age-eligible',      sprintf('%d players', $result['poolAgeEligible'])],
            ['In ability band',   sprintf('%d players (any age)', $result['poolInBand'])],
            ['Country enabled',   $result['countryEnabled'] ? 'yes' : 'no'],
        ]);

        $io->section('Player draw');
        $rows = [];
        foreach ($result['positions'] as $row) {
            $rows[] = [
                $row['position'],
                $row['requested'],
                ...array_column($row['funnel'], 'count'),
                $row['available'],
                $row['ok'] ? 'ok' : 'SHORT by ' . $row['shortfall'],
            ];
        }
        $funnelLabels = array_column($result['positions'][0]['funnel'] ?? [], 'label');
        $io->table(['Pos', 'Need', ...$funnelLabels, 'Available', 'Result'], $rows);
        $io->writeln(sprintf(
            ' Players: <options=bold>%d of %d</> available.',
            $result['playersAvailable'],
            $result['playersRequested'],
        ));

        $io->section('Staff & scouts');
        $staffRows = [];
        foreach ($result['staff'] as $row) {
            $staffRows[] = [$row['role'], $row['requested'], $row['native'], $row['available'], $row['ok'] ? 'ok' : 'SHORT'];
        }
        $staffRows[] = ['scout', $result['scouts']['requested'], $result['scouts']['native'], $result['scouts']['available'], $result['scouts']['ok'] ? 'ok' : 'SHORT'];
        $io->table(['Role', 'Need', 'Native', 'In pool', 'Result'], $staffRows);

        $io->section('Findings');
        foreach ($result['findings'] as $finding) {
            match ($finding['level']) {
                'danger'  => $io->writeln('  <fg=red;options=bold>✗</> ' . $finding['message']),
                'warning' => $io->writeln('  <fg=yellow;options=bold>!</> ' . $finding['message']),
                'success' => $io->writeln('  <fg=green;options=bold>✓</> ' . $finding['message']),
                default   => $io->writeln('  <fg=cyan>i</> ' . $finding['message']),
            };
        }

        $io->newLine();

        match ($result['verdict']) {
            'ok'       => $io->success('A full starter pack can be built.'),
            'degraded' => $io->warning('A starter pack can be built, but it will be under-filled.'),
            default    => $io->error('A starter pack CANNOT be built — club initialisation will fail.'),
        };

        return $result['verdict'] === 'ok' ? Command::SUCCESS : Command::FAILURE;
    }
}
