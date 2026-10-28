<?php

declare(strict_types=1);

namespace App\Repository\Friendly;

use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\Friendly\FriendlyChallengeSubmission;
use App\Enum\Friendly\FriendlySide;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FriendlyChallengeSubmission>
 */
class FriendlyChallengeSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FriendlyChallengeSubmission::class);
    }

    public function findForSide(FriendlyChallenge $challenge, FriendlySide $side): ?FriendlyChallengeSubmission
    {
        return $this->findOneBy(['challenge' => $challenge, 'side' => $side]);
    }

    /**
     * Store (or replace) one side's posted envelope.
     *
     * Mirrors FriendlyChallengeSquadRepository::upsertSide(), including writing through the
     * ORM rather than raw SQL: the envelope goes in as one whole array on a freshly loaded
     * entity, so the mixed-type json dirty-check trap that forces raw SQL on the competition
     * side does not arise.
     *
     * @param array<string, mixed> $envelope
     */
    public function upsertSide(FriendlyChallenge $challenge, FriendlySide $side, array $envelope): FriendlyChallengeSubmission
    {
        $submission = $this->findForSide($challenge, $side);

        if ($submission === null) {
            $submission = new FriendlyChallengeSubmission($challenge, $side, $envelope);
            $this->getEntityManager()->persist($submission);

            return $submission;
        }

        return $submission
            ->setEnvelopeJson($envelope)
            ->setSubmittedAt(new \DateTimeImmutable());
    }

    /**
     * Both participants' envelopes in one query, keyed by FriendlySide value.
     *
     * Mirrors FriendlyChallengeSquadRepository::findBothSides() — same shape, same reason.
     *
     * @return array<string, FriendlyChallengeSubmission>
     */
    public function findBothSides(FriendlyChallenge $challenge): array
    {
        $submissions = $this->createQueryBuilder('s')
            ->where('s.challenge = :challenge')
            ->setParameter('challenge', $challenge)
            ->getQuery()
            ->getResult();

        $bySide = [];
        foreach ($submissions as $submission) {
            $bySide[$submission->getSide()->value] = $submission;
        }

        return $bySide;
    }
}
