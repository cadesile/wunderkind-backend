<?php

declare(strict_types=1);

namespace App\Entity\Friendly;

use App\Enum\Friendly\FriendlySide;
use App\Repository\Friendly\FriendlyChallengeSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One participant's posted envelope for a friendly, verbatim — the friendly twin of
 * CompetitionEntrantSubmission, kept in step with it because both sides of the domain go
 * through the same CompetitionRegistrationService::splitSnapshot(). That shared chokepoint is
 * what stops the two drifting, so a change to what is stored belongs in both or neither.
 *
 * Keyed by side rather than venue, for the reason FriendlySide documents: venue decides who
 * is home and can change, side is fixed for the life of a challenge.
 *
 * Nothing on a client read path may load this. The public challenge board reads the display
 * blocks on FriendlyChallenge itself, and resolution reads the squad row.
 *
 * Unlike FriendlyChallengeSquad, which is pruned once the whistle goes, this is **kept** —
 * see CompetitionEntrantSubmission for the full reasoning.
 */
#[ORM\Entity(repositoryClass: FriendlyChallengeSubmissionRepository::class)]
#[ORM\Table(name: 'friendly_challenge_submission')]
#[ORM\UniqueConstraint(name: 'uq_friendly_challenge_submission_side', columns: ['challenge_id', 'side'])]
class FriendlyChallengeSubmission
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /** Owning side only — FriendlyChallenge holds no inverse collection, as on FriendlyChallengeSquad. */
    #[ORM\ManyToOne(targetEntity: FriendlyChallenge::class)]
    #[ORM\JoinColumn(name: 'challenge_id', nullable: false, onDelete: 'CASCADE')]
    private FriendlyChallenge $challenge;

    #[ORM\Column(type: 'string', length: 16, enumType: FriendlySide::class)]
    private FriendlySide $side;

    /** @var array<string, mixed> The whole posted body, verbatim. See CompetitionEntrantSubmission::$envelopeJson. */
    #[ORM\Column(type: 'json')]
    private array $envelopeJson;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $submittedAt;

    /** @param array<string, mixed> $envelopeJson */
    public function __construct(
        FriendlyChallenge $challenge,
        FriendlySide $side,
        array $envelopeJson,
        ?\DateTimeImmutable $submittedAt = null,
    ) {
        $this->id           = new UuidV7();
        $this->challenge    = $challenge;
        $this->side         = $side;
        $this->envelopeJson = $envelopeJson;
        $this->submittedAt  = $submittedAt ?? new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getChallenge(): FriendlyChallenge { return $this->challenge; }

    public function getSide(): FriendlySide { return $this->side; }

    /** @return array<string, mixed> */
    public function getEnvelopeJson(): array { return $this->envelopeJson; }
    /** @param array<string, mixed> $envelopeJson */
    public function setEnvelopeJson(array $envelopeJson): static { $this->envelopeJson = $envelopeJson; return $this; }

    public function getSubmittedAt(): \DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static { $this->submittedAt = $submittedAt; return $this; }

    /** Read-only virtual accessor for the admin detail view — needs a string, not an array column. */
    public function getEnvelopeJsonPretty(): string
    {
        return json_encode($this->envelopeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
