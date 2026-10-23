<?php

declare(strict_types=1);

namespace App\Command\Friendly;

use App\Service\Friendly\FriendlyChallengeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:friendly:expire-challenges',
    description: 'Expires open friendly challenges past their expiresAt and refunds escrowed stakes.',
)]
class ExpireFriendlyChallengesCommand extends Command
{
    public function __construct(
        private readonly FriendlyChallengeService $friendlyService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = $this->friendlyService->expireDueChallenges(new \DateTimeImmutable());
        $output->writeln(sprintf('Expired %d friendly challenge(s).', $expired));

        return Command::SUCCESS;
    }
}
