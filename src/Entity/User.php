<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ACADEMY = 'ROLE_ACADEMY';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?Academy $academy = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $managerProfile = null;

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

    public function getRoles(): array { return array_unique($this->roles); }

    public function setRoles(array $roles): void { $this->roles = $roles; }

    public function eraseCredentials(): void {}

    public function getAcademy(): ?Academy { return $this->academy; }
    public function setAcademy(?Academy $academy): void { $this->academy = $academy; }

    public function getManagerProfile(): ?array { return $this->managerProfile; }
    public function setManagerProfile(?array $profile): void { $this->managerProfile = $profile; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
