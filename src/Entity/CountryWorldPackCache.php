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

    /**
     * Shape version of the serialized payload, stamped from
     * WorldInitializationService::WORLD_PACK_VERSION at build time.
     *
     * Without it a cached row lives forever: the cache has no TTL, so a pack
     * built before a snapshot-shape change keeps being served verbatim until
     * someone remembers to run `app:warm-world-pack --force`. That is how packs
     * predating the personality work carried all-10s matrices — and packs
     * predating 2026-08-23 carry no `personality` key on staff/scout at all.
     *
     * Legacy rows default to 0, which never matches a current version, so they
     * are treated as a miss and rebuilt on first read.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $payloadVersion;

    public function __construct(string $country, int $tier, array $payload, int $payloadVersion = 0)
    {
        $this->id             = new UuidV7();
        $this->country        = $country;
        $this->tier           = $tier;
        $this->payload        = $payload;
        $this->payloadVersion = $payloadVersion;
        $this->generatedAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getCountry(): string { return $this->country; }
    public function getTier(): int { return $this->tier; }
    public function getPayload(): array { return $this->payload; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
    public function getPayloadVersion(): int { return $this->payloadVersion; }
}
