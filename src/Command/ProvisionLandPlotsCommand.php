<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Location\LandPlotProvisioner;
use App\Service\Location\LocationCapacityService;
use App\Service\Location\LocationSeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Give every town the plots its size calls for.
 *
 * Seeding used to create exactly one plot per town regardless of whether it was a village or a
 * capital. Plot count now follows city size, which follows population, which enrichment
 * supplies — so this is the pass that turns an enriched population into how much football a
 * place can hold.
 *
 * TOPS UP ONLY, never removes: a plot can carry a claim and real money. A town that re-bands
 * downward keeps what it has and stops gaining more. Safe to re-run at any time.
 */
#[AsCommand(
    name: 'app:locations:provision-plots',
    description: 'Top every town up to the number of land plots its city size calls for.',
)]
class ProvisionLandPlotsCommand extends Command
{
    public function __construct(
        private readonly LandPlotProvisioner $provisioner,
        private readonly \App\Repository\Location\LocationConfigRepository $configRepository,
        private readonly LocationSeedService $seedService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('countries', InputArgument::IS_ARRAY, 'Country codes (default: all seedable)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be added and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $codes = $input->getArgument('countries');

        if ($codes === []) {
            $codes = array_map(
                static fn ($c): string => $c->value,
                $this->seedService->seedableCountries(),
            );
        }

        // The LIVE figures, not the defaults. These are operator-tunable now, so printing the
        // constants would tell you what a fresh install does rather than what this run will.
        $targets = $this->configRepository->getConfig()->getPlotsByCitySize();

        $io->text(sprintf(
            'Targets: %s. NPC clubs take at most %d plot(s) per town.',
            implode(', ', array_map(
                static fn (string $size, int $n): string => "{$size}={$n}",
                array_keys($targets),
                $targets,
            )),
            LocationCapacityService::NPC_PLOT_CAP,
        ));

        $io->text('Only priced towns are provisioned — an unpriced one stays at zero plots until enrichment values it.');

        if ($dryRun) {
            $io->note('Dry run — nothing will be written.');
        }

        $rows       = [];
        $totalPlots = 0;

        foreach ($codes as $code) {
            $result = $this->provisioner->provisionCountry((string) $code, $dryRun);

            if ($result['plotsAdded'] === 0) {
                continue;
            }

            $rows[]      = [(string) $code, $result['locations'], $result['plotsAdded']];
            $totalPlots += $result['plotsAdded'];
        }

        if ($rows !== []) {
            $io->table(['Country', 'Towns topped up', 'Plots added'], $rows);
        }

        $io->success(sprintf(
            $dryRun ? 'Would add %d plot(s).' : 'Added %d plot(s).',
            $totalPlots,
        ));

        return Command::SUCCESS;
    }
}
