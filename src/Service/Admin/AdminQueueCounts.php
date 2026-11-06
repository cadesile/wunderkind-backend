<?php

namespace App\Service\Admin;

use App\Entity\BetaRequest;
use App\Entity\DeletionRequest;
use App\Enum\DeletionRequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The two "something is waiting for you" counts the sidebar badges.
 *
 * These run on every admin page load, so they are cached for a minute and kept
 * to two COUNTs. A badge that is a minute stale is not a problem — it exists to
 * say a queue is non-empty, not to be an exact figure — but a pair of queries
 * on every page would be.
 *
 * Both definitions are deliberately narrower than "all rows on that page":
 *
 *   Beta requests    verified but not yet invited. These are the rows the
 *                    "Send invite" action exists for. Counting everything
 *                    not-yet-invited would fold in unverified and expired
 *                    requests, which need no action at all.
 *
 *   Deletion requests  FAILED only, which is what
 *                      DeletionRequestStatus::needsAttention() already means.
 *                      Completed and rejected requests are finished work.
 */
final class AdminQueueCounts
{
    private const TTL = 60;

    private const CACHE_KEY = 'admin.queue_counts';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {}

    /** @return array{beta: int, deletion: int} */
    public function get(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);

            return [
                'beta' => (int) $this->em->createQuery(
                    'SELECT COUNT(b.id) FROM ' . BetaRequest::class . ' b
                     WHERE b.verifiedAt IS NOT NULL AND b.invitedAt IS NULL'
                )->getSingleScalarResult(),

                'deletion' => $this->em->getRepository(DeletionRequest::class)
                    ->count(['status' => DeletionRequestStatus::FAILED]),
            ];
        });
    }

    /**
     * Drop the cached counts.
     *
     * Call this after an action that empties one of the queues — sending a beta
     * invite, say — so the badge is right on the redirect rather than up to a
     * minute behind.
     */
    public function invalidate(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }
}
