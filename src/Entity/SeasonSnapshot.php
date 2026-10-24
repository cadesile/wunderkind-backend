<?php

namespace App\Entity;

use App\Repository\SeasonSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: SeasonSnapshotRepository::class)]
#[ORM\Table(name: 'season_snapshot')]
#[ORM\UniqueConstraint(name: 'uq_season_snapshot_club_season', columns: ['club_id', 'season'])]
class SeasonSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Club $club;

    #[ORM\Column(type: 'smallint')]
    private int $season;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'json')]
    private array $snapshotData;

    /**
     * The exact body POST /api/league/conclude-season answered with the first time this
     * season was concluded, replayed verbatim to any duplicate or late retry.
     *
     * Stored rather than recomputed because concluding is not a pure read: it re-rolls
     * every league sponsor's `rolledValue` (shared, country-wide state) and emits a fresh
     * fixture list. Recomputing on a retry would hand the client a *different* world than
     * the one the first call committed, and would re-roll sponsors for every other player
     * in that country as a side effect. See LeagueService::concludeSeason.
     *
     * Null on rows written before this shipped, and on the narrow window where the first
     * attempt committed the archive and then died before the pyramid was built — both are
     * handled by rebuilding once and storing the result.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $responseJson = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Club $club,
        int $season,
        string $country,
        array $snapshotData,
    ) {
        $this->id           = new UuidV7();
        $this->club      = $club;
        $this->season       = $season;
        $this->country      = $country;
        $this->snapshotData = $snapshotData;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getSeason(): int { return $this->season; }
    public function getCountry(): string { return $this->country; }
    public function getSnapshotData(): array { return $this->snapshotData; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getResponseJson(): ?array { return $this->responseJson; }

    public function setResponseJson(?array $responseJson): static
    {
        $this->responseJson = $responseJson;

        return $this;
    }
}
