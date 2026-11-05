<?php

declare(strict_types=1);

namespace App\Command\Friendly;

use App\Service\Friendly\FriendlyChallengeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron entry point (every 1 min — accept sets kickoffAt = now + 15 minutes, so a player is
 * waiting on the result).
 *
 * QUEUES every due friendly, one message each onto the `friendly` transport — it no longer
 * resolves them. The resolving happens on docker/friendly-consume.sh.
 */
#[AsCommand(
    name: 'app:friendly:resolve-matches',
    description: 'Queues every accepted friendly challenge whose kickoffAt has passed for resolution.',
)]
class ResolveFriendlyMatchesCommand extends Command
{
    public function __construct(
        private readonly FriendlyChallengeService $friendlyService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Queues rather than resolves. Resolving one friendly runs the match engine and
        // narrative generation for a full 90 minutes, and how many come due in the same
        // minute is decided by players rather than by a schedule — so an inline tick was
        // unbounded work in one 256M process on a busy evening. See
        // FriendlyChallengeService::dispatchDueMatches().
        $queued = $this->friendlyService->dispatchDueMatches(new \DateTimeImmutable());

        $output->writeln($queued > 0
            ? sprintf('Queued %d due friendly match(es) for resolution.', $queued)
            : 'No friendly match is due for resolution.');

        return Command::SUCCESS;
    }
}
