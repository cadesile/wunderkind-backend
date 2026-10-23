<?php

declare(strict_types=1);

namespace App\Command\Friendly;

use App\Service\Friendly\FriendlyChallengeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:friendly:resolve-matches',
    description: 'Resolves accepted friendly challenges whose kickoffAt has passed.',
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
        $resolved = $this->friendlyService->resolveDueMatches(new \DateTimeImmutable());
        $output->writeln(sprintf('Resolved %d friendly match(es).', $resolved));

        return Command::SUCCESS;
    }
}
