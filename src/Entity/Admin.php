<?php

namespace App\Entity;

use App\Repository\AdminRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AdminRepository::class)]
class Admin
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\OneToOne(inversedBy: 'admin')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $department = null;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 1])]
    private int $accessLevel = 1;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user)
    {
        $this->id        = new UuidV7();
        $this->user      = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function getDepartment(): ?string { return $this->department; }
    public function setDepartment(?string $department): void { $this->department = $department; }

    public function getAccessLevel(): int { return $this->accessLevel; }
    public function setAccessLevel(int $accessLevel): void { $this->accessLevel = $accessLevel; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
