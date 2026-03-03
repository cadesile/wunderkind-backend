<?php

namespace App\Command;

use App\Repository\AcademyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:set-existing-academy-balances',
    description: 'Set balance for existing academies that were created before the balance field existed',
)]
class SetExistingAcademyBalancesCommand extends Command
{
    public function __construct(
        private readonly AcademyRepository      $academyRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $academies = $this->academyRepository->findAll();
        $updated   = 0;

        foreach ($academies as $academy) {
            if ($academy->getBalance() === 0) {
                $academy->setBalance(500000); // £5,000 in pence
                $io->writeln("Set balance for academy: {$academy->getName()}");
                $updated++;
            }
        }

        $this->em->flush();

        $io->success("Done! Updated {$updated} academy/academies.");
        return Command::SUCCESS;
    }
}
