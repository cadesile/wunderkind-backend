<?php

namespace App\Command;

use App\Entity\Investor;
use App\Entity\Sponsor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup:assigned-entities',
    description: 'Permanently delete assigned market entities older than 52 weeks.',
)]
class CleanupAssignedEntitiesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $cutoff = new \DateTimeImmutable('-52 weeks');

        $io->title('Cleaning up assigned market entities older than 52 weeks');
        $io->info('Cutoff: ' . $cutoff->format('Y-m-d H:i:s'));

        // Sponsors — bulk DQL
        $deletedSponsors = $this->em->createQueryBuilder()
            ->delete(Sponsor::class, 's')
            ->where('s.assignedAt IS NOT NULL')
            ->andWhere('s.assignedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        // Investors — bulk DQL
        $deletedInvestors = $this->em->createQueryBuilder()
            ->delete(Investor::class, 'i')
            ->where('i.assignedAt IS NOT NULL')
            ->andWhere('i.assignedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        $total = $deletedSponsors + $deletedInvestors;

        $io->success([
            "Cleanup complete — {$total} entities removed:",
            "  Sponsors : {$deletedSponsors}",
            "  Investors: {$deletedInvestors}",
        ]);

        return Command::SUCCESS;
    }
}
