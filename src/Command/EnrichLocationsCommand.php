<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Country;
use App\Enum\Location\EnrichmentStatus;
use App\Repository\Location\LocationRepository;
use App\Service\Location\Enrichment\EnrichmentSweepInProgressException;
use App\Service\Location\Enrichment\LocationEnricherInterface;
use App\Service\Location\Enrichment\LocationEnrichmentOrchestrator;
use App\Service\Location\Enrichment\LocationEnrichmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI face of location enrichment — the same orchestrator the admin screen drives.
 *
 * Deliberately one command with one code path behind it, so the thing an operator reaches for
 * at 2am behaves exactly like the button in the back office.
 *
 * `--sync` is the prompt-iteration loop. It runs the enricher in-process for a handful of rows
 * so you can read what the provider actually said, adjust the prompt, and go again — without a
 * worker, and without queueing 865 paid calls to find out the first one was wrong. Always use
 * it with a small `--limit` first; that is the whole reason it exists.
 *
 * `--dry-run` calls nothing and queues nothing. It reports what WOULD be claimed, using the
 * same count the admin confirmation shows.
 */
#[AsCommand(
    name: 'app:locations:enrich',
    description: 'Queue AI enrichment for locations missing coordinates or a land value.',
)]
class EnrichLocationsCommand extends Command
{
    public function __construct(
        private readonly LocationEnrichmentOrchestrator $orchestrator,
        private readonly LocationEnrichmentService      $enrichmentService,
        private readonly LocationRepository             $locationRepository,
        private readonly LocationEnricherInterface      $enricher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::OPTIONAL, 'Restrict to one country code, e.g. EN.')
            ->addOption(
                'status',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated statuses to claim: pending, failed, needs_review.',
                'pending,failed',
            )
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum locations to claim.', '2000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be claimed; call and queue nothing.')
            ->addOption(
                'sync',
                null,
                InputOption::VALUE_NONE,
                'Run the enricher in-process instead of queueing — for iterating on the prompt. Use a small --limit.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $sync    = (bool) $input->getOption('sync');
        $limit   = max(1, (int) $input->getOption('limit'));
        $country = $input->getArgument('country');
        $country = $country === null ? null : strtoupper(trim((string) $country));

        if ($country !== null && Country::tryFrom($country) === null) {
            $io->error("'{$country}' is not an App\\Enum\\Country case.");

            return Command::FAILURE;
        }

        $statuses = $this->parseStatuses((string) $input->getOption('status'), $io);

        if ($statuses === null) {
            return Command::FAILURE;
        }

        $io->definitionList(
            ['Provider' => $this->enricher->provider()],
            ['Model' => $this->enricher->model()],
            ['Prompt' => $this->enricher->promptVersion()],
            ['Scope' => $country ?? 'all countries'],
            ['Statuses' => implode(', ', array_map(static fn (EnrichmentStatus $s): string => $s->value, $statuses))],
        );

        // Reported up front rather than left to fail mid-sweep. This is the check that would
        // have caught the missing-Firebase-secret incident before it discarded a day of pushes.
        $connection = $this->enricher->checkConnection();

        if (!$connection['ok']) {
            $io->warning("Enricher connection check failed: {$connection['detail']}");

            if (!$dryRun) {
                $io->error('Refusing to queue work against an enricher that cannot be reached. Use --dry-run to inspect scope anyway.');

                return Command::FAILURE;
            }
        } else {
            $io->success("Enricher reachable: {$connection['detail']}");
        }

        $outstanding = $this->orchestrator->countOutstanding($country, $statuses);

        if ($outstanding === 0) {
            $io->success('Nothing outstanding — every location in scope is already enriched.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('<info>%d</info> location(s) outstanding in scope.', $outstanding));

        if ($dryRun) {
            $io->note(sprintf('Dry run — would claim up to %d. Nothing queued, nothing called.', min($outstanding, $limit)));

            return Command::SUCCESS;
        }

        return $sync
            ? $this->runSynchronously($country, $statuses, $limit, $io)
            : $this->queue($country, $statuses, $limit, $io);
    }

    /** @param list<EnrichmentStatus> $statuses */
    private function queue(?string $country, array $statuses, int $limit, SymfonyStyle $io): int
    {
        try {
            $run = $this->orchestrator->queueAllOutstanding($country, null, $statuses, $limit);
        } catch (EnrichmentSweepInProgressException $e) {
            // Non-zero so a wrapping script does not quietly double-spend.
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($run === null) {
            $io->warning('Claimed nothing — another sweep or the lazy read path got there first.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Queued %d location(s) as run %s. Drain with: docker/enrichment-consume.sh '
            . '(or `messenger:consume enrichment` locally).',
            $run->getQueued(),
            $run->getId(),
        ));

        return Command::SUCCESS;
    }

    /**
     * In-process, no queue — the prompt-iteration path.
     *
     * @param list<EnrichmentStatus> $statuses
     */
    private function runSynchronously(?string $country, array $statuses, int $limit, SymfonyStyle $io): int
    {
        $claimed = $this->locationRepository->claimBatchForEnrichment(
            $statuses,
            new \DateTimeImmutable(),
            $limit,
            $country,
        );

        if ($claimed === []) {
            $io->warning('Claimed nothing.');

            return Command::SUCCESS;
        }

        $io->note(sprintf('Running %d enrichment(s) in-process.', count($claimed)));

        $rows     = [];
        $failures = 0;

        foreach ($claimed as $locationId) {
            $location = $this->enrichmentService->beginWork($locationId);

            if ($location === null) {
                continue;
            }

            $town = $location->getTown();

            try {
                $attempt = $this->enrichmentService->enrich($location);

                $rows[] = [
                    $town,
                    $attempt->getOutcome()->value,
                    $this->summarise($attempt->getAcceptedFields()),
                    $this->summarise($attempt->getRejectedFields()),
                    $attempt->getDurationMs() === null ? '—' : $attempt->getDurationMs() . 'ms',
                ];
            } catch (\Throwable $e) {
                ++$failures;
                $rows[] = [$town, 'error', '—', mb_substr($e->getMessage(), 0, 60), '—'];
            }
        }

        $io->table(['Town', 'Outcome', 'Accepted', 'Rejected / reason', 'Took'], $rows);

        if ($failures > 0) {
            $io->error(sprintf('%d of %d call(s) failed outright.', $failures, count($rows)));

            return Command::FAILURE;
        }

        $io->success('Done. Inspect the rejected column before running a full sweep.');

        return Command::SUCCESS;
    }

    /** @param array<string, mixed>|null $fields */
    private function summarise(?array $fields): string
    {
        if ($fields === null || $fields === []) {
            return '—';
        }

        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = is_scalar($value) ? "{$key}=" . (string) $value : $key;
        }

        return mb_substr(implode(', ', $parts), 0, 70);
    }

    /** @return list<EnrichmentStatus>|null */
    private function parseStatuses(string $raw, SymfonyStyle $io): ?array
    {
        $statuses = [];

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $status = EnrichmentStatus::tryFrom($token);

            if ($status === null) {
                $io->error("'{$token}' is not an enrichment status.");

                return null;
            }

            if ($status === EnrichmentStatus::ENRICHED || $status === EnrichmentStatus::QUEUED) {
                // Re-enriching an already-enriched row is a separate product decision that
                // interacts with sell-back pricing, and re-claiming a queued row would
                // duplicate a call already in flight.
                $io->error("'{$token}' cannot be claimed — only pending, failed and needs_review can.");

                return null;
            }

            $statuses[] = $status;
        }

        if ($statuses === []) {
            $io->error('--status named no claimable status.');

            return null;
        }

        return $statuses;
    }
}
