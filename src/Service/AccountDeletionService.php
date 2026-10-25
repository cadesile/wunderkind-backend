<?php

namespace App\Service;

use App\Entity\Club;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Permanently deletes a user account and every club they own, including all
 * club-dependent data that ORM/DB cascades don't cover. Runs in one transaction.
 */
final class AccountDeletionService
{
    /**
     * Club dependents with an ON-DELETE NO-ACTION FK at the DB level (verified against the
     * live schema via pg_constraint) — they must be deleted before their club or the FK blocks.
     *
     * SyncRecord and LeaderboardEntry are configured with ORM `cascade: ['remove']` on the Club
     * side, but that cascade only walks the in-memory Club::$syncRecords / $leaderboardEntries
     * collections. Those collections are never populated here (SyncRecord/LeaderboardEntry/Club
     * don't sync the inverse side on construction, and $user/$club may already be identity-mapped
     * from earlier in the same request, so a fresh query doesn't rehydrate them either) — so the
     * ORM cascade silently cascades over an empty collection and does nothing. They're deleted
     * explicitly here for that reason, same as the DB-verified NO-ACTION dependents below.
     *
     * InboxMessage is DB-level ON DELETE CASCADE; Transfer is DB-level SET NULL; email_verification
     * is DB-level CASCADE on user — all three are safe to leave to the DB and are handled by
     * remove($user)/remove($club) below without any explicit query.
     *
     * UserLedger is here despite user_ledger.user_id being ON DELETE CASCADE: that cascade only
     * fires when the *user* row goes, and clubs are deleted first, so user_ledger.club_id (plain
     * NO ACTION, and NOT NULL — it cannot be nulled out like transfer.club_id) blocks the club
     * delete long before the user is reached. Deleting the whole account is the one case where
     * discarding these rows is unambiguously right: the audit trail they protect belongs to a
     * user who is being erased outright.
     */
    /** Accounts per transaction in deleteAccountsBulk() — keeps IN() parameter lists bounded. */
    private const BULK_CHUNK_SIZE = 200;

    private const BLOCKING_CLUB_DEPENDENTS = [
        'SeasonSnapshot',
        'SeasonRecord',
        'MatchResult',
        'Investor',
        'Sponsor',
        'SyncRecord',
        'LeaderboardEntry',
        'UserLedger',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeaderboardCalculationService $leaderboardCalculationService,
    ) {}

    /**
     * Set-based deletion of many accounts at once — same tables, same order, same transaction
     * discipline as deleteAccount() below, but one statement per table for the whole batch
     * instead of per account.
     *
     * deleteAccount() is right for a single GDPR erasure and wrong for a bulk purge: it opens
     * a transaction and invalidates **every** leaderboard category per account, so clearing
     * the 3,604 spoof accounts a test database had accumulated meant 3,604 transactions and
     * ~43,000 cache invalidations. That is slow enough to time out the deploy step and the
     * admin button that call it — the exact failure being cleaned up after.
     *
     * Deliberately shares BLOCKING_CLUB_DEPENDENTS with deleteAccount() rather than restating
     * the table list: that const is the single record of which FKs block a club delete, and a
     * second copy would drift the first time one changes.
     *
     * @param list<User> $users every club owned by each of these is deleted, so the caller is
     *                          responsible for confirming that is what it wants
     *
     * @return array{users: int, clubs: int}
     */
    public function deleteAccountsBulk(array $users): array
    {
        if ($users === []) {
            return ['users' => 0, 'clubs' => 0];
        }

        $deletedClubs = 0;

        // Chunked so the IN() parameter list stays sane on a large purge; each chunk is its
        // own transaction, so a failure part-way leaves whole accounts deleted or untouched,
        // never a club stripped of its dependents but still present.
        foreach (array_chunk($users, self::BULK_CHUNK_SIZE) as $chunk) {
            $this->em->wrapInTransaction(function () use ($chunk, &$deletedClubs): void {
                /** @var list<Club> $clubs */
                $clubs = $this->em->createQuery('SELECT c FROM App\Entity\Club c WHERE c.user IN (:users)')
                    ->setParameter('users', $chunk)
                    ->getResult();

                if ($clubs !== []) {
                    foreach (self::BLOCKING_CLUB_DEPENDENTS as $entity) {
                        $this->em->createQuery("DELETE FROM App\\Entity\\{$entity} e WHERE e.club IN (:clubs)")
                            ->setParameter('clubs', $clubs)
                            ->execute();
                    }

                    $this->em->createQuery('DELETE FROM App\Entity\SeasonRatingsSnapshot e WHERE e.clubId IN (:ids)')
                        ->setParameter('ids', array_map(static fn (Club $c): string => (string) $c->getId(), $clubs))
                        ->execute();

                    $this->em->createQuery('DELETE FROM App\Entity\Friendly\FriendlyChallenge f WHERE f.challengerClub IN (:clubs) AND f.acceptorClub IS NULL')
                        ->setParameter('clubs', $clubs)
                        ->execute();

                    $this->em->createQuery('DELETE FROM App\Entity\Club c WHERE c IN (:clubs)')
                        ->setParameter('clubs', $clubs)
                        ->execute();

                    $deletedClubs += count($clubs);
                }

                $this->em->createQuery('DELETE FROM App\Entity\RefreshToken t WHERE t.username IN (:emails)')
                    ->setParameter('emails', array_map(static fn (User $u): string => $u->getUserIdentifier(), $chunk))
                    ->execute();

                $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u IN (:users)')
                    ->setParameter('users', $chunk)
                    ->execute();
            });
        }

        // Once, after everything has committed — not per account. Same reasoning as
        // deleteAccount()'s own invalidation, just not repeated thousands of times.
        if ($deletedClubs > 0) {
            foreach (LeaderboardCategory::cases() as $category) {
                $this->leaderboardCalculationService->invalidate($category);
            }
        }

        return ['users' => count($users), 'clubs' => $deletedClubs];
    }

    public function deleteAccount(User $user): void
    {
        $hadClubs = false;

        $this->em->wrapInTransaction(function () use ($user, &$hadClubs): void {
            // Queried directly (not via $user->getClubs()) so this is correct regardless of
            // whether the club collection is already hydrated/populated on the User instance.
            $clubs = $this->em->createQuery('SELECT c FROM App\Entity\Club c WHERE c.user = :user')
                ->setParameter('user', $user)
                ->getResult();

            foreach ($clubs as $club) {
                $hadClubs = true;

                foreach (self::BLOCKING_CLUB_DEPENDENTS as $entity) {
                    $this->em->createQuery("DELETE FROM App\\Entity\\{$entity} e WHERE e.club = :club")
                        ->setParameter('club', $club)
                        ->execute();
                }

                // Denormalized snapshot keyed by club id string (no FK, still the user's data).
                $this->em->createQuery('DELETE FROM App\Entity\SeasonRatingsSnapshot e WHERE e.clubId = :id')
                    ->setParameter('id', (string) $club->getId())
                    ->execute();

                $this->em->createQuery('DELETE FROM App\Entity\Friendly\FriendlyChallenge f WHERE f.challengerClub = :club AND f.acceptorClub IS NULL')
                    ->setParameter('club', $club)
                    ->execute();

                // Removed explicitly (not left to cascade from $user) so this doesn't depend on
                // User::$clubs being a populated/lazy-initialized collection at call time.
                $this->em->remove($club);
            }

            // Revoke standing sessions: no FK ties refresh_tokens.username to user.id (it's a
            // bare string, set to the JWT identifier at issue time), so nothing above touches
            // it — left alone, a still-valid refresh token could keep minting new access tokens
            // against an account that no longer exists in this table.
            $this->em->createQuery('DELETE FROM App\Entity\RefreshToken t WHERE t.username = :username')
                ->setParameter('username', $user->getUserIdentifier())
                ->execute();

            // DB cascade: InboxMessage (ON DELETE CASCADE on club) and email_verification
            // (ON DELETE CASCADE on user); DB SET NULL: transfer.club_id/player_id.
            $this->em->remove($user);
            $this->em->flush();
        });

        // Outside the transaction and only once it has committed: the LeaderboardEntry rows
        // are really gone at this point, so it's safe to drop every cached leaderboard page
        // rather than risk repopulating the cache from a read that raced the commit. Every
        // category is invalidated (not just the ones the deleted club scored in) because the
        // cache is keyed by category+period for the whole board, not per club.
        if ($hadClubs) {
            foreach (LeaderboardCategory::cases() as $category) {
                $this->leaderboardCalculationService->invalidate($category);
            }
        }
    }
}
