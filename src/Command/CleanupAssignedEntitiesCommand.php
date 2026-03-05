<?php

namespace App\Command;

use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\Sponsor;
use App\Entity\Staff;
use App\Entity\Transfer;
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

        // Players — remove via entity manager to trigger Doctrine cascade (Guardian)
        $oldPlayers = $this->em->getRepository(Player::class)
            ->createQueryBuilder('p')
            ->where('p.assignedAt IS NOT NULL')
            ->andWhere('p.assignedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        $deletedPlayers = 0;
        foreach ($oldPlayers as $player) {
            // Remove linked transfers (DB-level CASCADE also handles this)
            $transfers = $this->em->getRepository(Transfer::class)->findBy(['player' => $player]);
            foreach ($transfers as $transfer) {
                $this->em->remove($transfer);
            }
            $this->em->remove($player);
            $deletedPlayers++;
        }
        $this->em->flush();

        // Staff — remove via entity manager
        $oldStaff = $this->em->getRepository(Staff::class)
            ->createQueryBuilder('s')
            ->where('s.assignedAt IS NOT NULL')
            ->andWhere('s.assignedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        $deletedStaff = 0;
        foreach ($oldStaff as $staff) {
            $this->em->remove($staff);
            $deletedStaff++;
        }
        $this->em->flush();

        // Sponsors — bulk DQL (no complex cascades)
        $deletedSponsors = $this->em->createQueryBuilder()
            ->delete(Sponsor::class, 's')
            ->where('s.assignedAt IS NOT NULL')
            ->andWhere('s.assignedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        // Investors — bulk DQL (no complex cascades)
        $deletedInvestors = $this->em->createQueryBuilder()
            ->delete(Investor::class, 'i')
            ->where('i.assignedAt IS NOT NULL')
            ->andWhere('i.assignedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        $total = $deletedPlayers + $deletedStaff + $deletedSponsors + $deletedInvestors;

        $io->success([
            "Cleanup complete — {$total} entities removed:",
            "  Players  : {$deletedPlayers}",
            "  Staff    : {$deletedStaff}",
            "  Sponsors : {$deletedSponsors}",
            "  Investors: {$deletedInvestors}",
        ]);

        return Command::SUCCESS;
    }
}
