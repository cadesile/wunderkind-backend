<?php

namespace App\Repository\Friendly;

use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\Friendly\FriendlyChallengeAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FriendlyChallengeAudit>
 */
class FriendlyChallengeAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FriendlyChallengeAudit::class);
    }

    public function findForChallenge(FriendlyChallenge $challenge): ?FriendlyChallengeAudit
    {
        return $this->findOneBy(['challenge' => $challenge]);
    }

    /**
     * Replaces a challenge's event log, creating the row on first resolution.
     *
     * An upsert because a challenge can be force-resolved more than once in testing, and a
     * second attempt must not collide with the row the first left behind.
     *
     * @param list<array<string, mixed>> $eventLogJson
     */
    public function upsertForChallenge(FriendlyChallenge $challenge, array $eventLogJson): FriendlyChallengeAudit
    {
        $audit = $this->findForChallenge($challenge);

        if ($audit === null) {
            $audit = new FriendlyChallengeAudit($challenge, $eventLogJson);
            $this->getEntityManager()->persist($audit);

            return $audit;
        }

        return $audit->setEventLogJson($eventLogJson);
    }
}
