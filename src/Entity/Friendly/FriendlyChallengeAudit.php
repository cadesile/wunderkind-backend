<?php

declare(strict_types=1);

namespace App\Entity\Friendly;

use App\Repository\Friendly\FriendlyChallengeAuditRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A resolved friendly's minute-by-minute event log.
 *
 * The friendly twin of CompetitionResultAudit, split off for the same reason: the event log
 * has exactly one reader in the repository — the admin challenge detail page — yet it lived
 * on FriendlyChallenge, which the public challenge board hydrates for every COMPLETE
 * challenge it lists.
 *
 * There is no computation audit here. A friendly's engine result carries one, but
 * FriendlyChallenge::recordResult() never stored it, and inventing a column to hold
 * something nothing produces would be worse than leaving the gap visible. If a friendly ever
 * needs its derivation auditable, add the column here and populate it in
 * FriendlyChallengeService::resolveSingleMatch(), where the MatchEngineResult is in hand.
 *
 * As with the competition side: no inverse association from FriendlyChallenge, because a
 * OneToOne inverse cannot be proxied and is always fetched eagerly.
 */
#[ORM\Entity(repositoryClass: FriendlyChallengeAuditRepository::class)]
#[ORM\Table(name: 'friendly_challenge_audit')]
#[ORM\UniqueConstraint(name: 'uq_friendly_challenge_audit_challenge', columns: ['challenge_id'])]
class FriendlyChallengeAudit
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\OneToOne(targetEntity: FriendlyChallenge::class)]
    #[ORM\JoinColumn(name: 'challenge_id', nullable: false, onDelete: 'CASCADE')]
    private FriendlyChallenge $challenge;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $eventLogJson;

    /** @param list<array<string, mixed>> $eventLogJson */
    public function __construct(FriendlyChallenge $challenge, array $eventLogJson)
    {
        $this->id           = new UuidV7();
        $this->challenge    = $challenge;
        $this->eventLogJson = $eventLogJson;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getChallenge(): FriendlyChallenge { return $this->challenge; }

    /** @return list<array<string, mixed>> */
    public function getEventLogJson(): array { return $this->eventLogJson; }
    /** @param list<array<string, mixed>> $eventLogJson */
    public function setEventLogJson(array $eventLogJson): static { $this->eventLogJson = $eventLogJson; return $this; }
}
