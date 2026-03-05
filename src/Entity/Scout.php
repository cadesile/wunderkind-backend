<?php

namespace App\Entity;

use App\Repository\ScoutRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: ScoutRepository::class)]
class Scout
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dob = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(type: 'json')]
    private array $judgements = [];

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $experience = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name = '')
    {
        $this->id        = new UuidV7();
        $this->name      = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }

    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }

    public function getJudgements(): array { return $this->judgements; }
    public function setJudgements(array $judgements): void { $this->judgements = $judgements; }

    public function getExperience(): int { return $this->experience; }
    public function setExperience(int $experience): void { $this->experience = $experience; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
