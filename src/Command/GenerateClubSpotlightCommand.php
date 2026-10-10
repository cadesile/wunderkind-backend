<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ClubSpotlightService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:spotlight:generate',
    description: 'Pick a new landing page Club Spotlight from the most active clubs and notify its owner',
)]
class GenerateClubSpotlightCommand extends Command
{
    public function __construct(
        private readonly ClubSpotlightService $clubSpotlightService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $club = $this->clubSpotlightService->selectNew();

        if ($club === null) {
            $io->warning('No club met the minimum sync threshold in the trailing window — spotlight left unchanged.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Club Spotlight set to "%s".', $club->getName()));

        return Command::SUCCESS;
    }
}
