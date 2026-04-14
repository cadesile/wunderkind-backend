<?php

namespace App\Entity;

use App\Repository\SeasonSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: SeasonSnapshotRepository::class)]
#[ORM\Table(name: 'season_snapshot')]
class SeasonSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Academy $academy;

    #[ORM\Column(type: 'smallint')]
    private int $season;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'json')]
    private array $snapshotData;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Academy $academy,
        int $season,
        string $country,
        array $snapshotData,
    ) {
        $this->id           = new UuidV7();
        $this->academy      = $academy;
        $this->season       = $season;
        $this->country      = $country;
        $this->snapshotData = $snapshotData;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getAcademy(): Academy { return $this->academy; }
    public function getSeason(): int { return $this->season; }
    public function getCountry(): string { return $this->country; }
    public function getSnapshotData(): array { return $this->snapshotData; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
