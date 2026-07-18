<?php

namespace App\Service;

use App\Entity\User;
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
     */
    private const BLOCKING_CLUB_DEPENDENTS = [
        'SeasonSnapshot',
        'SeasonRecord',
        'MatchResult',
        'Investor',
        'Sponsor',
        'SyncRecord',
        'LeaderboardEntry',
    ];

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function deleteAccount(User $user): void
    {
        $this->em->wrapInTransaction(function () use ($user): void {
            // Queried directly (not via $user->getClubs()) so this is correct regardless of
            // whether the club collection is already hydrated/populated on the User instance.
            $clubs = $this->em->createQuery('SELECT c FROM App\Entity\Club c WHERE c.user = :user')
                ->setParameter('user', $user)
                ->getResult();

            foreach ($clubs as $club) {
                foreach (self::BLOCKING_CLUB_DEPENDENTS as $entity) {
                    $this->em->createQuery("DELETE FROM App\\Entity\\{$entity} e WHERE e.club = :club")
                        ->setParameter('club', $club)
                        ->execute();
                }

                // Denormalized snapshot keyed by club id string (no FK, still the user's data).
                $this->em->createQuery('DELETE FROM App\Entity\SeasonRatingsSnapshot e WHERE e.clubId = :id')
                    ->setParameter('id', (string) $club->getId())
                    ->execute();

                // Removed explicitly (not left to cascade from $user) so this doesn't depend on
                // User::$clubs being a populated/lazy-initialized collection at call time.
                $this->em->remove($club);
            }

            // DB cascade: InboxMessage (ON DELETE CASCADE on club) and email_verification
            // (ON DELETE CASCADE on user); DB SET NULL: transfer.club_id/player_id.
            $this->em->remove($user);
            $this->em->flush();
        });
    }
}
