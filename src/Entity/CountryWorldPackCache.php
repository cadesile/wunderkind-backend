<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\WorldPack\WorldPackSet;
use App\Repository\CountryWorldPackCacheRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One league tier's generated payload, belonging to exactly one WorldPackSet.
 *
 * **The uniqueness key is (set_id, tier), not (country, tier).** It was the latter for
 * as long as a country had exactly one world; a country now holds a buffer of sets, so
 * (country, tier) is no longer unique and `findOneBy(['country','tier'])` on this table
 * would return an arbitrary one of them. Reach a row through its set, never through its
 * denormalised country.
 *
 * `country` and `tier` stay on the row anyway: the admin summaries
 * (CountryWorldPackCacheRepository::findAllSummaries()) and the dashboard's stale-pack
 * count read them directly in SQL, and joining through the set to recover a value the
 * row can carry for two bytes would be worse.
 */
#[ORM\Entity(repositoryClass: CountryWorldPackCacheRepository::class)]
#[ORM\Table(name: 'country_world_pack_cache')]
#[ORM\UniqueConstraint(name: 'uq_world_pack_set_tier', columns: ['set_id', 'tier'])]
#[ORM\Index(columns: ['country', 'tier'], name: 'idx_country_world_pack_cache_country_tier')]
class CountryWorldPackCache
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /**
     * CASCADE: the set is the owner. Deleting a set — a regenerate clearing unclaimed
     * stock, or the prune dropping a long-claimed set's payloads — must take its tier
     * rows with it, or they become unreachable rows nothing can serve or clean up.
     */
    #[ORM\ManyToOne(targetEntity: WorldPackSet::class)]
    #[ORM\JoinColumn(name: 'set_id', nullable: false, onDelete: 'CASCADE')]
    private WorldPackSet $set;

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

    /**
     * When this tier was first handed to the club that claimed its set, or null if never.
     *
     * A served row is KEPT, not deleted, so a retried call replays the exact same bytes: the
     * client treats an empty or truncated 2xx as retryable, and a restarted load asks again
     * from the top. Deleting on serve made a retry build a fresh tier from a different
     * generation, which is a mixed world (Courtney, 2026-11-23: "we should never get mixed
     * worlds"). The rows go once the whole world has been handed over and a grace period has
     * passed — see `CountryWorldPackCacheRepository::deletePayloadsForWorldsInitialisedBefore()`.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $servedAt = null;

    public function __construct(WorldPackSet $set, int $tier, array $payload, int $payloadVersion = 0)
    {
        $this->id             = new UuidV7();
        $this->set            = $set;
        $this->country        = $set->getCountry();
        $this->tier           = $tier;
        $this->payload        = $payload;
        $this->payloadVersion = $payloadVersion;
        $this->generatedAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getSet(): WorldPackSet { return $this->set; }
    public function getCountry(): string { return $this->country; }
    public function getTier(): int { return $this->tier; }
    public function getPayload(): array { return $this->payload; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
    public function getPayloadVersion(): int { return $this->payloadVersion; }
    public function getServedAt(): ?\DateTimeImmutable { return $this->servedAt; }
    public function markServed(\DateTimeImmutable $at): void { $this->servedAt ??= $at; }
}
