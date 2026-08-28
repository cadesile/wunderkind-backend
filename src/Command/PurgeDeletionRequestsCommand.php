<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DeletionRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention control for the deletion audit trail.
 *
 * A COMPLETED row keeps the email address of an account that no longer exists —
 * necessary to evidence the deletion, but it is still personal data outliving its
 * subject, so it should not be kept forever. Run this on a schedule with whatever
 * retention period you have committed to.
 */
#[AsCommand(
    name: 'app:deletion-requests:purge',
    description: 'Delete account-deletion audit rows older than the retention period.',
)]
class PurgeDeletionRequestsCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 365;

    public function __construct(
        private readonly DeletionRequestRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention period in days', (string) self::DEFAULT_RETENTION_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');

        if ($days < 1) {
            $io->error('--days must be at least 1.');

            return Command::INVALID;
        }

        $cutoff = new \DateTimeImmutable("-{$days} days");

        if ($input->getOption('dry-run')) {
            $count = (int) $this->em->createQuery(
                'SELECT COUNT(d.id) FROM App\Entity\DeletionRequest d WHERE d.requestedAt < :cutoff'
            )->setParameter('cutoff', $cutoff)->getSingleScalarResult();

            $io->note(sprintf('%d row(s) older than %s would be deleted.', $count, $cutoff->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $deleted = $this->repository->deleteOlderThan($cutoff);
        $io->success(sprintf('Purged %d deletion request(s) older than %s.', $deleted, $cutoff->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
