<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Club;
use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\Friendly\FriendlyChallengeSquad;
use App\Enum\Friendly\FriendlySide;
use App\Enum\Tier;
use App\Service\Competition\CompetitionRegistrationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds friendly challenges the way FriendlyChallengeService does.
 *
 * The friendly twin of EntrantFixtureTrait, and it exists for the same reason: a challenge's
 * squad now lives in its own row, so a fixture that only sets the display block would be
 * testing a half-registered challenge that the real write path cannot produce — and the
 * match engine would see two empty sides without anything failing.
 */
trait FriendlyFixtureTrait
{
    /**
     * A submitted envelope, in the shape a client POSTs.
     *
     * @return array<string, mixed>
     */
    private function friendlyEnvelope(string $clubName, int $ability = 60): array
    {
        $players   = [];
        $positions = ['GK', 'DEF', 'DEF', 'DEF', 'DEF', 'MID', 'MID', 'MID', 'ATT', 'ATT', 'ATT'];
        foreach ($positions as $i => $position) {
            $players[] = [
                'id'             => sprintf('%s-p%d', $clubName, $i),
                'name'           => sprintf('%s Player %d', $clubName, $i),
                'position'       => $position,
                'currentAbility' => $ability,
            ];
        }

        return [
            'club'    => ['name' => $clubName, 'formation' => '4-4-2', 'cohesion' => 70, 'managerMotivation' => 65],
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => $clubName . ' Boss', 'ability' => 70]],
        ];
    }

    /**
     * Splits exactly as the service does, by calling the same method rather than
     * reimplementing it — a fixture that split by its own rules could seed a challenge the
     * real write path cannot produce, which is the whole hazard this trait exists to avoid.
     * Only the two fields postChallenge()/acceptChallenge() reassert from the live Club are
     * reasserted here too.
     *
     * @param array<string, mixed> $envelope
     * @return array{display: array<string, mixed>, engine: array<string, mixed>, players: list<mixed>, staff: list<mixed>, clientSnapshotAt: ?string, playerCount: int, ovr: int}
     */
    private function splitFriendlyEnvelope(array $envelope, Club $club): array
    {
        $parts = CompetitionRegistrationService::splitSnapshot($envelope, $club);

        $parts['display']['reputation']     = $club->getReputation();
        $parts['display']['reputationTier'] = Tier::fromScore($club->getReputation())->value;

        return $parts;
    }

    /**
     * An OPEN challenge, with its challenger squad row.
     *
     * @param array<string, mixed> $envelope
     */
    private function makeOpenChallenge(
        EntityManagerInterface $em,
        Club $challenger,
        array $envelope,
        string $venue = 'home',
        int $stakePence = 0,
        ?\DateTimeImmutable $expiresAt = null,
    ): FriendlyChallenge {
        $parts = $this->splitFriendlyEnvelope($envelope, $challenger);

        $challenge = new FriendlyChallenge(
            $challenger,
            $venue,
            $stakePence,
            $parts['display']['ovr'],
            $challenger->getReputation(),
            Tier::fromScore($challenger->getReputation())->value,
            $parts['display'],
            $expiresAt ?? new \DateTimeImmutable('+2 days'),
        );

        $em->persist($challenge);
        $em->persist(new FriendlyChallengeSquad(
            $challenge,
            FriendlySide::CHALLENGER,
            $parts['players'],
            $parts['staff'],
            $parts['engine'],
        ));

        return $challenge;
    }

    /**
     * Attaches an acceptor and its squad row, leaving the challenge ACCEPTED.
     *
     * @param array<string, mixed> $envelope
     */
    private function acceptChallengeFixture(
        EntityManagerInterface $em,
        FriendlyChallenge $challenge,
        Club $acceptor,
        array $envelope,
    ): FriendlyChallenge {
        $parts = $this->splitFriendlyEnvelope($envelope, $acceptor);

        $challenge->setAcceptorClub($acceptor)
            ->setAcceptorClubDisplayJson($parts['display'])
            ->setAcceptedAt(new \DateTimeImmutable('-1 hour'))
            ->setKickoffAt(new \DateTimeImmutable('-30 minutes'));

        $em->persist(new FriendlyChallengeSquad(
            $challenge,
            FriendlySide::ACCEPTOR,
            $parts['players'],
            $parts['staff'],
            $parts['engine'],
        ));

        return $challenge;
    }
}
