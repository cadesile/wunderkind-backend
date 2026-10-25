<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\User;
use App\Service\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes every spoof club and the throwaway account that owns it.
 *
 * Spoof clubs are admin-generated test data (CompetitionSpoofEntrantService — either
 * "Generate Spoof Entrants" on a competition or "Create Spoof" on the entrant screen). Each
 * one is created with its own dedicated User on the SPOOF_EMAIL_DOMAIN, so cleaning up means
 * deleting whole accounts, not just club rows.
 *
 * **Deletion goes through AccountDeletionService::deleteAccountsBulk() rather than a DELETE
 * statement.** Eight
 * tables reference `club` with ON DELETE NO ACTION (investor, leaderboard_entry, match_result,
 * season_record, season_snapshot, sponsor, sync_record, user_ledger), so a plain
 * `DELETE FROM club WHERE is_spoof` fails on a foreign key as soon as a spoof club has picked
 * any of them up. That service already deletes those dependents in order, inside a
 * transaction, and also handles the rows with no FK at all (SeasonRatingsSnapshot, keyed by
 * club id string) and the refresh tokens that would otherwise keep minting access tokens for
 * a deleted account. Re-implementing that ordering here would be a second copy to keep in
 * step with every future FK. The bulk entry point exists because the per-account one opens a
 * transaction and invalidates every leaderboard category each time, which at a few thousand
 * spoof accounts is slow enough to time out the deploy step and the admin button that call
 * this.
 *
 * **A user is only deleted when every club they own is a spoof** and their email is on the
 * spoof domain. Both conditions, not either: deleteAccount() removes ALL of a user's clubs,
 * so a user who somehow owned one spoof club and one real one would lose the real one too.
 * That combination should be impossible — createSpoofClub() always mints a fresh user — but
 * "should be impossible" is not a reason to let a destructive command take the risk, so those
 * are reported and skipped rather than deleted.
 */
#[AsCommand(
    name: 'app:clubs:purge-spoof',
    description: 'Delete every spoof club and its throwaway owning account.',
)]
class PurgeSpoofClubsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountDeletionService $accountDeletionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // JOINs the owner rather than lazy-loading it per club: the owning User is read for
        // every single row (email + club count), so a proxy per club is both an N+1 and a
        // hard failure if a club ever outlives its owner — an uninitialisable proxy throws
        // EntityNotFoundException rather than returning null. The JOIN simply omits such a
        // club, and the count below reports it instead of killing the run.
        /** @var list<Club> $spoofClubs */
        $spoofClubs = $this->em->createQuery('SELECT c, u FROM App\Entity\Club c JOIN c.user u WHERE c.isSpoof = true')->getResult();

        $ownerlessCount = (int) $this->em->createQuery(
            'SELECT COUNT(c.id) FROM App\Entity\Club c WHERE c.isSpoof = true AND c.user IS NULL'
        )->getSingleScalarResult();

        if ($ownerlessCount > 0) {
            $io->warning(sprintf('%d spoof club(s) have no owning account and were left alone.', $ownerlessCount));
        }

        if ($spoofClubs === []) {
            $io->success('No spoof clubs to purge.');

            return Command::SUCCESS;
        }

        // Group by owner: one user can own several spoof clubs, and deleteAccount() takes the
        // whole account, so each must be visited exactly once.
        $clubsByOwner = [];
        foreach ($spoofClubs as $club) {
            $clubsByOwner[(string) $club->getUser()->getId()][] = $club;
        }

        // One query for every owner's total club count, rather than one per owner: a purge of
        // several thousand spoof accounts is the normal case, not the exception.
        $ownedCounts = [];
        foreach ($this->em->createQuery(
            'SELECT IDENTITY(c.user) AS userId, COUNT(c.id) AS total FROM App\Entity\Club c WHERE c.user IN (:users) GROUP BY c.user'
        )->setParameter('users', array_map(static fn (array $clubs): User => $clubs[0]->getUser(), $clubsByOwner))->getArrayResult() as $row) {
            $ownedCounts[(string) $row['userId']] = (int) $row['total'];
        }

        $purgeable    = [];
        $deletedClubs = 0;
        $skipped      = [];

        foreach ($clubsByOwner as $userId => $clubs) {
            $user       = $clubs[0]->getUser();
            $ownedCount = $ownedCounts[(string) $userId] ?? count($clubs);

            $allOwnedAreSpoof = $ownedCount === count($clubs);
            $hasSpoofEmail    = User::isSpoofEmail($user->getEmail());

            if (!$allOwnedAreSpoof || !$hasSpoofEmail) {
                $skipped[] = sprintf(
                    '%s (%d spoof of %d owned clubs%s)',
                    $user->getEmail(),
                    count($clubs),
                    $ownedCount,
                    $hasSpoofEmail ? '' : ', not a spoof account',
                );

                continue;
            }

            $purgeable[]   = $user;
            $deletedClubs += count($clubs);
        }

        // Spoof accounts that own no club at all never appear in a club-keyed sweep, so they
        // would otherwise accumulate forever — a spoof registration that failed after creating
        // its user, or an account whose clubs were removed some other way. The email domain is
        // unambiguous (User::SPOOF_EMAIL_DOMAIN is never issued by a real registration) and
        // there is nothing left to lose, so they go too.
        /** @var list<User> $clublessSpoofUsers */
        $clublessSpoofUsers = $this->em->createQuery(
            'SELECT u FROM App\Entity\User u
             WHERE u.email LIKE :domain
               AND NOT EXISTS (SELECT c.id FROM App\Entity\Club c WHERE c.user = u)'
        )->setParameter('domain', '%' . User::SPOOF_EMAIL_DOMAIN)->getResult();

        $purgeable = array_merge($purgeable, $clublessSpoofUsers);

        if ($dryRun) {
            $deletedUsers = count($purgeable);
        } else {
            $result       = $this->accountDeletionService->deleteAccountsBulk($purgeable);
            $deletedClubs = $result['clubs'];
            $deletedUsers = $result['users'];
        }

        if ($clublessSpoofUsers !== []) {
            $io->note(sprintf('%d spoof account(s) owned no club and were removed too.', count($clublessSpoofUsers)));
        }

        if ($skipped !== []) {
            $io->warning(sprintf(
                "Skipped %d account(s) that own a mix of spoof and real clubs, or are not spoof accounts — "
                . "deleting them would take real clubs with them:\n  %s",
                count($skipped),
                implode("\n  ", $skipped),
            ));
        }

        $io->success(sprintf(
            '%s %d spoof club(s) across %d account(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $deletedClubs,
            $deletedUsers,
        ));

        return Command::SUCCESS;
    }
}
