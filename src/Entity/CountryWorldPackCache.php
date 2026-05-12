<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CountryWorldPackCacheRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: CountryWorldPackCacheRepository::class)]
#[ORM\Table(name: 'country_world_pack_cache')]
#[ORM\UniqueConstraint(name: 'uq_country_tier', columns: ['country', 'tier'])]
class CountryWorldPackCache
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 2, options: ['fixed' => true])]
    private string $country;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column]
    private \DateTimeImmutable $generatedAt;

    public function __construct(string $country, int $tier, array $payload)
    {
        $this->id          = new UuidV7();
        $this->country     = $country;
        $this->tier        = $tier;
        $this->payload     = $payload;
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getCountry(): string { return $this->country; }
    public function getTier(): int { return $this->tier; }
    public function getPayload(): array { return $this->payload; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
}
