<?php

namespace App\Repository\Friendly;

use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\Friendly\FriendlyChallengeSquad;
use App\Enum\Friendly\FriendlySide;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FriendlyChallengeSquad>
 */
class FriendlyChallengeSquadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FriendlyChallengeSquad::class);
    }

    public function findForSide(FriendlyChallenge $challenge, FriendlySide $side): ?FriendlyChallengeSquad
    {
        return $this->findOneBy(['challenge' => $challenge, 'side' => $side]);
    }

    /**
     * Both sides of one challenge, keyed by side value — the shape match resolution wants.
     *
     * @return array<string, FriendlyChallengeSquad>
     */
    public function findBothSides(FriendlyChallenge $challenge): array
    {
        $bySide = [];
        foreach ($this->findBy(['challenge' => $challenge]) as $squad) {
            $bySide[$squad->getSide()->value] = $squad;
        }

        return $bySide;
    }

    /**
     * Replaces one side's engine inputs, creating the row if this is the first submission.
     *
     * An upsert rather than an insert because a challenge's acceptor is written later than
     * its challenger, and because re-accepting after a failed resolve must not collide with
     * the row left behind.
     *
     * @param list<array<string, mixed>> $players
     * @param list<array<string, mixed>> $staff
     * @param array<string, mixed> $engineInputs
     */
    public function upsertSide(
        FriendlyChallenge $challenge,
        FriendlySide $side,
        array $players,
        array $staff,
        array $engineInputs,
    ): FriendlyChallengeSquad {
        $squad = $this->findForSide($challenge, $side);

        if ($squad === null) {
            $squad = new FriendlyChallengeSquad($challenge, $side, $players, $staff, $engineInputs);
            $this->getEntityManager()->persist($squad);

            return $squad;
        }

        // Written through the ORM, unlike the competition side's raw SQL: these payloads go
        // in as whole arrays on a freshly loaded entity, so the mixed string/int json
        // dirty-check problem that forces raw SQL in CompetitionRegistrationService does not
        // arise here — there is no partial update to miss.
        return $squad
            ->setPlayersJson($players)
            ->setStaffJson($staff)
            ->setClubEngineJson($engineInputs);
    }

    /**
     * Drops both sides' engine inputs for a resolved challenge.
     *
     * Safe only once the match has been played: these rows are the engine's only input, so
     * clearing them for an accepted-but-unresolved challenge would make it unplayable.
     */
    public function deleteForChallenge(FriendlyChallenge $challenge): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM friendly_challenge_squad WHERE challenge_id = :challengeId',
            ['challengeId' => $challenge->getId()->toRfc4122()],
        );
    }
}
