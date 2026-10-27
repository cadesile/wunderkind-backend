<?php

declare(strict_types=1);

namespace App\Entity\Friendly;

use App\Entity\Club;
use App\Enum\Friendly\FriendlyChallengeStatus;
use App\Repository\Friendly\FriendlyChallengeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: FriendlyChallengeRepository::class)]
#[ORM\Table(name: 'friendly_challenge')]
#[ORM\Index(columns: ['status', 'expires_at'], name: 'idx_friendly_challenge_status_expires')]
#[ORM\Index(columns: ['status', 'kickoff_at'], name: 'idx_friendly_challenge_status_kickoff')]
#[ORM\Index(columns: ['challenger_club_id', 'status'], name: 'idx_friendly_challenge_challenger_status')]
#[ORM\Index(columns: ['acceptor_club_id', 'status'], name: 'idx_friendly_challenge_acceptor_status')]
class FriendlyChallenge
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(name: 'challenger_club_id', nullable: true, onDelete: 'SET NULL')]
    private ?Club $challengerClub;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(name: 'acceptor_club_id', nullable: true, onDelete: 'SET NULL')]
    private ?Club $acceptorClub = null;

    #[ORM\Column(name: 'challenger_club_uuid', type: 'string', length: 36)]
    private string $challengerClubId;

    #[ORM\Column(name: 'acceptor_club_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $acceptorClubId = null;

    #[ORM\Column(type: 'string', length: 36)]
    private string $challengerUserId;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $acceptorUserId = null;

    /** 'home' | 'away' — where the poster (challenger) plays. */
    #[ORM\Column(type: 'string', length: 10)]
    private string $venue;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $stakePence;

    #[ORM\Column(type: 'string', enumType: FriendlyChallengeStatus::class)]
    private FriendlyChallengeStatus $status;

    #[ORM\Column(type: 'smallint')]
    private int $challengerOvr;

    #[ORM\Column(type: 'smallint')]
    private int $challengerReputation;

    #[ORM\Column(type: 'string', length: 20)]
    private string $challengerReputationTier;

    /**
     * The challenger's bounded club display block — see
     * CompetitionRegistrationService::clubDisplayBlock().
     *
     * This replaced a whole inline squad snapshot. GET /api/friendlies/challenges is public
     * and lists every open challenge, so rendering a board of names, kits and badges decoded
     * two full squads per row: the same fault as the competition bracket, with a smaller
     * multiplier. The XI, staff and engine-only club keys now live in
     * FriendlyChallengeSquad, which nothing on a client read path may load.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $challengerClubDisplayJson;

    /**
     * The acceptor's display block, or null while the challenge is unaccepted.
     *
     * The null is load-bearing, not merely an empty value: it is how the service, the match
     * detail serializer and the board all recognise a challenge nobody has taken yet. Do not
     * default it to an empty array.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $acceptorClubDisplayJson = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $kickoffAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolveLockedAt = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $homeScore = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $awayScore = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $homeClubJson = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $awayClubJson = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $homeLineupJson = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $awayLineupJson = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $eventLogJson = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $narrativePayload = null;

    public function __construct(
        Club $challengerClub,
        string $venue,
        int $stakePence,
        int $challengerOvr,
        int $challengerReputation,
        string $challengerReputationTier,
        array $challengerClubDisplayJson,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id                       = new UuidV7();
        $this->challengerClub           = $challengerClub;
        $this->challengerClubId         = (string) $challengerClub->getId();
        $this->challengerUserId         = (string) $challengerClub->getUser()->getId();
        $this->venue                    = $venue;
        $this->stakePence               = $stakePence;
        $this->status                   = FriendlyChallengeStatus::OPEN;
        $this->challengerOvr            = $challengerOvr;
        $this->challengerReputation     = $challengerReputation;
        $this->challengerReputationTier = $challengerReputationTier;
        $this->challengerClubDisplayJson = $challengerClubDisplayJson;
        $this->createdAt                = new \DateTimeImmutable();
        $this->expiresAt                = $expiresAt;
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getChallengerClub(): ?Club { return $this->challengerClub; }
    public function getAcceptorClub(): ?Club { return $this->acceptorClub; }
    public function setAcceptorClub(?Club $club): static
    {
        $this->acceptorClub   = $club;
        $this->acceptorClubId = $club !== null ? (string) $club->getId() : null;
        $this->acceptorUserId = $club !== null ? (string) $club->getUser()->getId() : null;

        return $this;
    }

    public function getChallengerClubId(): string { return $this->challengerClubId; }
    public function getAcceptorClubId(): ?string { return $this->acceptorClubId; }
    public function getChallengerUserId(): string { return $this->challengerUserId; }
    public function getAcceptorUserId(): ?string { return $this->acceptorUserId; }
    public function getVenue(): string { return $this->venue; }
    public function getStakePence(): int { return $this->stakePence; }
    public function getStatus(): FriendlyChallengeStatus { return $this->status; }
    public function setStatus(FriendlyChallengeStatus $status): static { $this->status = $status; return $this; }
    public function getChallengerOvr(): int { return $this->challengerOvr; }
    public function getChallengerReputation(): int { return $this->challengerReputation; }
    public function getChallengerReputationTier(): string { return $this->challengerReputationTier; }
    /** @return array<string, mixed> */
    public function getChallengerClubDisplayJson(): array { return $this->challengerClubDisplayJson; }
    /** @param array<string, mixed> $block */
    public function setChallengerClubDisplayJson(array $block): static { $this->challengerClubDisplayJson = $block; return $this; }

    /** @return array<string, mixed>|null Null means the challenge has not been accepted. */
    public function getAcceptorClubDisplayJson(): ?array { return $this->acceptorClubDisplayJson; }
    /** @param array<string, mixed>|null $block */
    public function setAcceptorClubDisplayJson(?array $block): static { $this->acceptorClubDisplayJson = $block; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): static { $this->acceptedAt = $acceptedAt; return $this; }
    public function getKickoffAt(): ?\DateTimeImmutable { return $this->kickoffAt; }
    public function setKickoffAt(?\DateTimeImmutable $kickoffAt): static { $this->kickoffAt = $kickoffAt; return $this; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static { $this->resolvedAt = $resolvedAt; return $this; }
    public function getResolveLockedAt(): ?\DateTimeImmutable { return $this->resolveLockedAt; }
    public function setResolveLockedAt(?\DateTimeImmutable $resolveLockedAt): static { $this->resolveLockedAt = $resolveLockedAt; return $this; }
    public function getHomeScore(): ?int { return $this->homeScore; }
    public function getAwayScore(): ?int { return $this->awayScore; }
    public function getHomeClubJson(): ?array { return $this->homeClubJson; }
    public function getAwayClubJson(): ?array { return $this->awayClubJson; }
    public function getHomeLineupJson(): ?array { return $this->homeLineupJson; }
    public function getAwayLineupJson(): ?array { return $this->awayLineupJson; }
    public function getEventLogJson(): ?array { return $this->eventLogJson; }
    public function getNarrativePayload(): ?array { return $this->narrativePayload; }

    public function recordResult(
        int $homeScore,
        int $awayScore,
        array $homeClubJson,
        array $awayClubJson,
        array $homeLineupJson,
        array $awayLineupJson,
        array $eventLogJson,
        array $narrativePayload,
        \DateTimeImmutable $resolvedAt,
    ): static {
        $this->homeScore        = $homeScore;
        $this->awayScore        = $awayScore;
        $this->homeClubJson     = $homeClubJson;
        $this->awayClubJson     = $awayClubJson;
        $this->homeLineupJson   = $homeLineupJson;
        $this->awayLineupJson   = $awayLineupJson;
        $this->eventLogJson     = $eventLogJson;
        $this->narrativePayload = $narrativePayload;
        $this->resolvedAt       = $resolvedAt;
        $this->status           = FriendlyChallengeStatus::COMPLETE;

        return $this;
    }
}
