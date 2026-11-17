<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\WorldPack\WorldPackSetStatus;
use App\Repository\WorldPack\WorldPackSetRepository;
use App\Service\WorldPack\WorldPackSetTopUpService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds a country's world pack SET buffer: clears the unclaimed stock and builds a fresh
 * set per requested slot, each with its own starter squad.
 *
 * ── What changed, and why `--tier` is now an error ───────────────────────────────────────
 *
 * This command used to warm individual `(country, tier)` cache rows, with `--tier=N`,
 * `--batch-size` and `--force`. A world pack is now a SET — one starter squad plus a payload
 * for every tier, claimed whole by one club — and half a set is not servable, so there is no
 * meaningful "warm tier 3 only" any more. `--tier` is rejected with an explanation rather
 * than quietly ignored, because silently warming the whole country for someone who asked for
 * one tier is worse than refusing.
 *
 * `--force` is likewise gone: clearing the unclaimed stock is now what this command always
 * does, and it is safe, because claimed sets are spared. The hazard the old `--force` carried
 * — a country left with no packs at all for the length of the run — no longer exists either,
 * since the sets being replaced are the ones nobody has claimed.
 *
 * Use `app:worldpack:topup` to top a country up to target without clearing anything, and
 * `app:worldpack:maintain` to sweep every live country (that is the one on a cron).
 */
#[AsCommand(
    name: 'app:worldpack:warm',
    description: 'Rebuild a country\'s world pack set buffer (clears unclaimed stock, keeps claimed).',
)]
class WarmWorldPackCommand extends Command
{
    public function __construct(
        private readonly WorldPackSetTopUpService $topUpService,
        private readonly WorldPackSetRepository   $setRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. EN, ES)')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many sets to build. Defaults to StarterConfig::worldPackSetTarget.')
            ->addOption('tier', null, InputOption::VALUE_REQUIRED, 'No longer supported — a set must be whole to be servable. See the class docblock.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $country = strtoupper(trim((string) $input->getArgument('country')));

        if (strlen($country) !== 2) {
            $io->error("Country code must be exactly 2 characters. Got: '{$country}'.");

            return Command::FAILURE;
        }

        if ($input->getOption('tier') !== null) {
            $io->error([
                '--tier is no longer supported.',
                'A world pack is now a SET — a starter squad plus every league tier — claimed whole by one club.',
                'A set missing a tier is not servable, so there is nothing useful a single-tier warm can produce.',
                'Run without --tier to rebuild the country, or use app:worldpack:topup to add one set.',
            ]);

            return Command::FAILURE;
        }

        $countOpt = $input->getOption('count');
        $count    = $countOpt !== null ? (int) $countOpt : null;

        if ($count !== null && $count < 1) {
            $io->error('--count must be at least 1.');

            return Command::FAILURE;
        }

        $target = $count ?? $this->topUpService->targetSetCount();

        $io->title("Rebuilding world pack sets for {$country}");
        $io->text("Target: {$target} set(s). Claimed sets are kept; unclaimed stock is replaced.");

        $built = $this->topUpService->rebuild($country, $count);

        if ($built === []) {
            $io->error([
                "No sets were built for {$country}.",
                'Most likely the country has no League rows, or the player pool cannot supply a starter squad.',
                "Check with: php bin/console app:starter-pack:check {$country}",
            ]);

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($built as $set) {
            $rows[] = [
                (string) $set->getId(),
                $set->getStatus()->value,
                $set->getTierCount(),
            ];
        }
        $io->table(['Set', 'Status', 'Tiers'], $rows);

        $ready   = $this->setRepository->countByStatus($country, WorldPackSetStatus::READY);
        $claimed = $this->setRepository->countByStatus($country, WorldPackSetStatus::CLAIMED);

        if (count($built) < $target) {
            $io->warning(sprintf(
                'Built %d of %d requested — the pool ran short. The sets that did build are live.',
                count($built),
                $target,
            ));
        }

        $io->success(sprintf(
            '%s now holds %d ready set(s) (%d claimed).',
            $country,
            $ready,
            $claimed,
        ));

        return Command::SUCCESS;
    }
}
