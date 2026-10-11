<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Signs an account out everywhere. Single writer for both halves of that job —
 * either one alone leaves the user partially logged in:
 *
 *  - Access tokens are stateless JWTs with a 1h TTL and no denylist, so they
 *    are retired by bumping User::$tokenVersion, which JwtTokenVersionSubscriber
 *    compares against each token's `tv` claim. Immediate.
 *  - Refresh tokens ARE stored (refresh_tokens, 30d TTL, single_use: false), so
 *    they have to be deleted outright or the client simply mints a new access
 *    token seconds later and the bump achieves nothing.
 *
 * Rows are matched on `username`, which is what the refresh-token bundle stores
 * and is User::getUserIdentifier() — the email, not the id.
 */
class SessionRevocationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Unexpired refresh tokens for the account — what the admin UI calls
     * "active sessions". Access tokens are stateless and uncountable by
     * definition, so this is a floor, not a total.
     */
    public function countActiveSessions(User $user): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM refresh_tokens WHERE username = :username AND valid > NOW()',
            ['username' => $user->getUserIdentifier()],
        );
    }

    /**
     * @return int how many refresh tokens were deleted — the only part of this
     *             with a countable before/after, and worth surfacing to the
     *             admin so "killed" doesn't silently mean "there was nothing"
     */
    public function revokeAllSessions(User $user): int
    {
        $user->bumpTokenVersion();
        $this->em->flush();

        $deleted = (int) $this->connection->executeStatement(
            'DELETE FROM refresh_tokens WHERE username = :username',
            ['username' => $user->getUserIdentifier()],
        );

        $this->logger->info('Admin revoked all sessions for a user account.', [
            'userId'                => (string) $user->getId(),
            'tokenVersion'          => $user->getTokenVersion(),
            'refreshTokensDeleted'  => $deleted,
        ]);

        return $deleted;
    }
}
