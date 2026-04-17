<?php

namespace App\Command;

use App\Repository\ClubRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:set-existing-club-balances',
    description: 'Set balance for existing academies that were created before the balance field existed',
)]
class SetExistingClubBalancesCommand extends Command
{
    public function __construct(
        private readonly ClubRepository      $clubRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $academies = $this->clubRepository->findAll();
        $updated   = 0;

        foreach ($academies as $club) {
            if ($club->getBalance() === 0) {
                $club->setBalance(500000); // £5,000 in pence
                $io->writeln("Set balance for club: {$club->getName()}");
                $updated++;
            }
        }

        $this->em->flush();

        $io->success("Done! Updated {$updated} club/academies.");
        return Command::SUCCESS;
    }
}
