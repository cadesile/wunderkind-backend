<?php

namespace App\Entity;

use App\Repository\AdminRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AdminRepository::class)]
class Admin implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $department = null;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 1])]
    private int $accessLevel = 1;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email)
    {
        $this->id        = new UuidV7();
        $this->email     = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }

    public function getRoles(): array { return ['ROLE_ADMIN']; }

    public function eraseCredentials(): void {}

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): void { $this->name = $name; }

    public function getDepartment(): ?string { return $this->department; }
    public function setDepartment(?string $department): void { $this->department = $department; }

    public function getAccessLevel(): int { return $this->accessLevel; }
    public function setAccessLevel(int $accessLevel): void { $this->accessLevel = $accessLevel; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
