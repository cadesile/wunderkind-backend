<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WorldPack\WorldPackSetTopUpService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tops one country up to its world pack set target, clearing nothing.
 *
 * The hand-run twin of the TopUpWorldPackSetMessage the init endpoint dispatches, and the
 * difference from `app:worldpack:warm` is the whole point: warm replaces the unclaimed stock,
 * this only adds what is missing. Builds nothing when the country is already at target, so it
 * is safe to run repeatedly.
 */
#[AsCommand(
    name: 'app:worldpack:topup',
    description: 'Top one country up to its world pack set target. Adds only; clears nothing.',
)]
class TopUpWorldPackSetCommand extends Command
{
    public function __construct(private readonly WorldPackSetTopUpService $topUpService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. EN, ES)')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Most sets to build in this run', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $country = strtoupper(trim((string) $input->getArgument('country')));
        $max     = max(1, (int) $input->getOption('max'));

        if (strlen($country) !== 2) {
            $io->error("Country code must be exactly 2 characters. Got: '{$country}'.");

            return Command::FAILURE;
        }

        $built = $this->topUpService->topUp($country, $max);

        if ($built === []) {
            // Not a failure. The overwhelmingly common reason is that the country is already
            // stocked, which is the healthy state this command exists to maintain.
            $io->success("{$country} needed no top-up (already at target, or a build is already in flight).");

            return Command::SUCCESS;
        }

        foreach ($built as $set) {
            $io->writeln(sprintf(
                '  built set %s — %d tier(s)',
                (string) $set->getId(),
                $set->getTierCount(),
            ));
        }

        $io->success(sprintf('Built %d set(s) for %s.', count($built), $country));

        return Command::SUCCESS;
    }
}
