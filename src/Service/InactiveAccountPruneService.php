<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DeletionRequest;
use App\Entity\InactiveAccountWarning;
use App\Entity\User;
use App\Enum\DeletionRequestStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds, warns and deletes accounts that NEVER PLAYED and have not been seen for a long time
 * (app:users:prune-inactive, Courtney 2026-11-27).
 *
 * THE RULE IS "NO TRACE OF EVER PLAYING", NOT "NO CLUB RIGHT NOW". Deleting a user cascades
 * away their plot claims, plot holds, owner ledger, devices and quiz answers, and after the
 * go-live buyout many real players will have owner credit and no club. So an account is only a
 * candidate when ALL of these hold, checked in one SQL statement (candidateSql()):
 *
 *   - created before the cutoff AND last seen before the cutoff (lastSeenSql());
 *   - no club, no club_sale, no leaderboard archive row: it never had a club that synced;
 *   - no user_ledger row at all: any credit or history protects the account;
 *   - no plot_claim or plot_hold, in any status;
 *   - not an admin role, not debug-flagged, not a spoof account, not on the protected list.
 *
 * Anyone with any sync or club history is never touched, however long they have been away.
 *
 * LAST SEEN is the latest of sign-up, last password login, any device check-in, and the issue
 * time of the newest refresh token (valid - 30 day TTL, see gesdinet_jwt_refresh_token.yaml).
 */
final class InactiveAccountPruneService
{
    /** Must match gesdinet_jwt_refresh_token.ttl: a token's issue time is valid - TTL. */
    private const REFRESH_TOKEN_TTL_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountDeletionService $deletionService,
    ) {}

    /**
     * Every never-played account last seen before $cutoff, oldest-seen first, with its warning
     * (if any). Read-only.
     *
     * @param list<string> $protectedEmails lower-cased
     *
     * @return list<array{id: string, email: string, created_at: string, last_seen_at: string, warned_at: ?string, delete_after: ?string}>
     */
    public function findCandidates(\DateTimeImmutable $cutoff, array $protectedEmails): array
    {
        $sql = sprintf(
            'SELECT c.id, c.email, c.created_at, c.last_seen_at, w.warned_at, w.delete_after
               FROM (%s) c
               LEFT JOIN inactive_account_warning w ON w.user_id::text = c.id
              WHERE c.last_seen_at < :cutoff
              ORDER BY c.last_seen_at ASC, c.id ASC',
            $this->candidateSql(),
        );

        // An empty IN () is a syntax error, so the list always holds a value no email can be.
        $protected = $protectedEmails === [] ? ['__none__'] : array_values($protectedEmails);

        /** @var list<array{id: string, email: string, created_at: string, last_seen_at: string, warned_at: ?string, delete_after: ?string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            $sql,
            ['cutoff' => $cutoff->format('Y-m-d H:i:sP'), 'protected' => $protected, 'spoof' => '%' . User::SPOOF_EMAIL_DOMAIN],
            ['protected' => ArrayParameterType::STRING],
        );

        return $rows;
    }

    /**
     * Warnings that no longer apply: the account was seen after it was warned, or it is no
     * longer a candidate at all (made a club, got a plot, was protected). Removing them is what
     * re-arms the warning, so a later lapse gets a fresh email and a fresh countdown.
     *
     * @param list<string> $candidateIds the ids findCandidates() returned for this run
     *
     * @return list<array{user_id: string, email: string}>
     */
    public function findStaleWarnings(array $candidateIds): array
    {
        $ids = $candidateIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $candidateIds;

        /** @var list<array{user_id: string, email: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT w.user_id::text AS user_id, u.email
               FROM inactive_account_warning w
               JOIN "user" u ON u.id = w.user_id
              WHERE w.user_id::text NOT IN (:ids)
                 OR ' . $this->lastSeenSql('u') . ' > w.warned_at',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return $rows;
    }

    /** @param list<string> $userIds */
    public function clearWarnings(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return (int) $this->em->getConnection()->executeStatement(
            'DELETE FROM inactive_account_warning WHERE user_id::text IN (:ids)',
            ['ids' => $userIds],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    public function recordWarning(string $userId, \DateTimeImmutable $warnedAt, \DateTimeImmutable $deleteAfter): void
    {
        $user = $this->em->getReference(User::class, $userId);
        $this->em->persist(new InactiveAccountWarning($user, $warnedAt, $deleteAfter));
        $this->em->flush();
    }

    /**
     * Deletes the given accounts through the same bulk path as every other account delete, then
     * writes one PRUNED_INACTIVE audit row each. Re-checks each id against the candidate rule
     * inside this call, so an account that made a club or logged in since the scan survives.
     *
     * @param list<string> $userIds
     *
     * @return list<string> emails actually deleted
     */
    public function deleteAccounts(array $userIds, \DateTimeImmutable $cutoff, array $protectedEmails): array
    {
        if ($userIds === []) {
            return [];
        }

        $stillEligible = [];
        foreach ($this->findCandidates($cutoff, $protectedEmails) as $row) {
            if (in_array($row['id'], $userIds, true) && $row['warned_at'] !== null
                && new \DateTimeImmutable($row['last_seen_at']) <= new \DateTimeImmutable($row['warned_at'])) {
                $stillEligible[] = $row['id'];
            }
        }

        if ($stillEligible === []) {
            return [];
        }

        /** @var list<User> $users */
        $users  = $this->em->getRepository(User::class)->findBy(['id' => $stillEligible]);
        $emails = array_map(static fn (User $u): string => $u->getEmail(), $users);

        $this->deletionService->deleteAccountsBulk($users);
        $this->em->clear();

        foreach ($emails as $email) {
            $this->em->persist(new DeletionRequest($email, DeletionRequestStatus::PRUNED_INACTIVE));
        }
        $this->em->flush();

        return $emails;
    }

    /**
     * Never-played accounts with their last-seen time. Parameters: :protected (list), :spoof.
     */
    private function candidateSql(): string
    {
        return 'SELECT u.id::text AS id, u.email, u.created_at, ' . $this->lastSeenSql('u') . ' AS last_seen_at
                  FROM "user" u
                 WHERE NOT EXISTS (SELECT 1 FROM club c        WHERE c.user_id = u.id)
                   AND NOT EXISTS (SELECT 1 FROM club_sale s   WHERE s.user_id = u.id)
                   AND NOT EXISTS (SELECT 1 FROM user_ledger l WHERE l.user_id = u.id)
                   AND NOT EXISTS (SELECT 1 FROM plot_claim p  WHERE p.user_id = u.id)
                   AND NOT EXISTS (SELECT 1 FROM plot_hold h   WHERE h.user_id = u.id)
                   AND NOT EXISTS (SELECT 1 FROM leaderboard_period_archive_entry a WHERE a.user_id = u.id::text)
                   AND u.debug_logging_enabled = false
                   AND u.roles::text NOT LIKE \'%ADMIN%\'
                   AND LOWER(u.email) NOT IN (:protected)
                   AND u.email NOT LIKE :spoof';
    }

    private function lastSeenSql(string $u): string
    {
        $ttl = self::REFRESH_TOKEN_TTL_DAYS;

        return "GREATEST(
                    {$u}.created_at,
                    COALESCE({$u}.last_login_at, {$u}.created_at),
                    COALESCE((SELECT MAX(d.last_active_at) FROM user_device d WHERE d.user_id = {$u}.id), {$u}.created_at),
                    COALESCE((SELECT MAX(t.valid) - INTERVAL '{$ttl} days' FROM refresh_tokens t WHERE t.username = {$u}.email), {$u}.created_at)
                )";
    }
}
