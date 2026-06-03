<?php

namespace App\Entity;

use App\Enum\ReputationTier;
use App\Enum\TrophyColour;
use App\Repository\LeagueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: LeagueRepository::class)]
#[ORM\Table(name: 'league')]
#[ORM\UniqueConstraint(name: 'uq_league_country_tier', columns: ['country', 'tier'])]
class League
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $promotionSpots = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $tvDeal = null;

    #[ORM\Column(type: 'string', enumType: ReputationTier::class, nullable: true)]
    private ?ReputationTier $leagueReputationTier = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $prizeMoney = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $leaguePositionPot = null;

    /** How many sponsors should be sourced for this league during world cache generation. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sponsorCount = 0;

    /** Slug of the trophy silhouette, e.g. "trophy-3". Serves /images/trophies/{slug}.svg */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $trophyImage = null;

    #[ORM\Column(type: 'string', enumType: TrophyColour::class, nullable: true)]
    private ?TrophyColour $trophyColour = null;

    #[ORM\OneToMany(mappedBy: 'league', targetEntity: LeagueSponsor::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $leagueSponsors;

    #[ORM\ManyToMany(targetEntity: Sponsor::class)]
    #[ORM\JoinTable(
        name: 'league_sponsor',
        joinColumns: [new ORM\JoinColumn(name: 'league_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'sponsor_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    )]
    private Collection $sponsors;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $country, int $tier, string $name)
    {
        $this->id             = new UuidV7();
        $this->country        = $country;
        $this->tier           = $tier;
        $this->name           = $name;
        $this->leagueSponsors = new ArrayCollection();
        $this->sponsors       = new ArrayCollection();
        $this->createdAt      = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getCountry(): string { return $this->country; }
    public function setCountry(string $v): static { $this->country = $v; return $this; }

    public function getTier(): int { return $this->tier; }
    public function setTier(int $v): static { $this->tier = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getPromotionSpots(): ?int { return $this->promotionSpots; }
    public function setPromotionSpots(?int $v): static { $this->promotionSpots = $v; return $this; }

    public function getTvDeal(): ?int { return $this->tvDeal; }
    public function setTvDeal(?int $v): static { $this->tvDeal = $v; return $this; }

    public function getLeagueReputationTier(): ?ReputationTier { return $this->leagueReputationTier; }
    public function setLeagueReputationTier(?ReputationTier $v): static { $this->leagueReputationTier = $v; return $this; }

    public function getPrizeMoney(): ?int { return $this->prizeMoney; }
    public function setPrizeMoney(?int $v): static { $this->prizeMoney = $v; return $this; }

    public function getLeaguePositionPot(): ?int { return $this->leaguePositionPot; }
    public function setLeaguePositionPot(?int $v): static { $this->leaguePositionPot = $v; return $this; }

    public function getSponsorCount(): int { return $this->sponsorCount; }
    public function setSponsorCount(int $v): static { $this->sponsorCount = max(0, $v); return $this; }

    public function getTrophyImage(): ?string { return $this->trophyImage; }
    public function setTrophyImage(?string $v): static { $this->trophyImage = $v; return $this; }

    public function getTrophyColour(): ?TrophyColour { return $this->trophyColour; }
    public function setTrophyColour(?TrophyColour $v): static { $this->trophyColour = $v; return $this; }

    /** @return Collection<int, LeagueSponsor> */
    public function getLeagueSponsors(): Collection { return $this->leagueSponsors; }

    /** @return Collection<int, Sponsor> */
    public function getSponsors(): Collection
    {
        return $this->sponsors;
    }

    public function addSponsor(Sponsor $sponsor): static
    {
        if (!$this->sponsors->contains($sponsor)) {
            $this->sponsors->add($sponsor);
            $this->leagueSponsors->add(new LeagueSponsor($this, $sponsor));
        }
        return $this;
    }

    public function removeSponsor(Sponsor $sponsor): static
    {
        $this->sponsors->removeElement($sponsor);
        foreach ($this->leagueSponsors as $ls) {
            if ((string) $ls->getSponsor()->getId() === (string) $sponsor->getId()) {
                $this->leagueSponsors->removeElement($ls);
                break;
            }
        }
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
