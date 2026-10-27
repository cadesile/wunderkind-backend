<?php

declare(strict_types=1);

namespace App\Entity\Friendly;

use App\Enum\Friendly\FriendlySide;
use App\Repository\Friendly\FriendlyChallengeSquadRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One participant's match-engine inputs for a friendly: the starting XI, the staff, and the
 * club-level tuning keys the engine reads.
 *
 * The friendly twin of CompetitionEntrantSquad, and split out for the same reason. A
 * challenge used to carry two whole snapshots inline, and GET /api/friendlies/challenges is
 * public and lists every open challenge — so rendering a board of club names, kits and
 * badges decoded two full squads per row. Same fault as the bracket endpoint, just a
 * smaller multiplier.
 *
 * Keyed by side rather than by venue: see FriendlySide for why that distinction matters.
 *
 * Nothing on a client read path may load this. The board and the match summary read the
 * display blocks on FriendlyChallenge itself; the only legitimate readers are match
 * resolution, the provisional-XI branch of a single match detail, and the admin viewer.
 */
#[ORM\Entity(repositoryClass: FriendlyChallengeSquadRepository::class)]
#[ORM\Table(name: 'friendly_challenge_squad')]
#[ORM\UniqueConstraint(name: 'uq_friendly_challenge_squad_side', columns: ['challenge_id', 'side'])]
class FriendlyChallengeSquad
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /**
     * Owning side only — FriendlyChallenge holds no inverse collection. An inverse OneToMany
     * would be cheap to hydrate lazily, but the board lists challenges in bulk and an
     * accidental `->getSquads()` in a serializer is exactly the regression this split exists
     * to prevent. Fetch through the repository instead.
     */
    #[ORM\ManyToOne(targetEntity: FriendlyChallenge::class)]
    #[ORM\JoinColumn(name: 'challenge_id', nullable: false, onDelete: 'CASCADE')]
    private FriendlyChallenge $challenge;

    #[ORM\Column(type: 'string', length: 16, enumType: FriendlySide::class)]
    private FriendlySide $side;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $playersJson;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $staffJson;

    /** @var array<string, mixed> See CompetitionRegistrationService::CLUB_ENGINE_KEYS. */
    #[ORM\Column(type: 'json')]
    private array $clubEngineJson;

    /**
     * @param list<array<string, mixed>> $playersJson
     * @param list<array<string, mixed>> $staffJson
     * @param array<string, mixed> $clubEngineJson
     */
    public function __construct(
        FriendlyChallenge $challenge,
        FriendlySide $side,
        array $playersJson,
        array $staffJson = [],
        array $clubEngineJson = [],
    ) {
        $this->id             = new UuidV7();
        $this->challenge      = $challenge;
        $this->side           = $side;
        $this->playersJson    = $playersJson;
        $this->staffJson      = $staffJson;
        $this->clubEngineJson = $clubEngineJson;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getChallenge(): FriendlyChallenge { return $this->challenge; }

    public function getSide(): FriendlySide { return $this->side; }

    /** @return list<array<string, mixed>> */
    public function getPlayersJson(): array { return $this->playersJson; }
    /** @param list<array<string, mixed>> $playersJson */
    public function setPlayersJson(array $playersJson): static { $this->playersJson = $playersJson; return $this; }

    /** @return list<array<string, mixed>> */
    public function getStaffJson(): array { return $this->staffJson; }
    /** @param list<array<string, mixed>> $staffJson */
    public function setStaffJson(array $staffJson): static { $this->staffJson = $staffJson; return $this; }

    /** @return array<string, mixed> */
    public function getClubEngineJson(): array { return $this->clubEngineJson; }
    /** @param array<string, mixed> $clubEngineJson */
    public function setClubEngineJson(array $clubEngineJson): static { $this->clubEngineJson = $clubEngineJson; return $this; }

    /** Read-only virtual accessor for the admin detail view — needs a string, not an array column. */
    public function getSquadJsonPretty(): string
    {
        return json_encode(
            ['club' => $this->clubEngineJson, 'players' => $this->playersJson, 'staff' => $this->staffJson],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';
    }
}
