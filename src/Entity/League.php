<?php

namespace App\Entity;

use App\Enum\ReputationTier;
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

    /**
     * The PER-CLUB TV share, in pence. Unchanged in name and meaning since the first release.
     *
     * KEPT AS THE PER-CLUB FIGURE DELIBERATELY, even though the authored value is now the pool
     * below. Shipped builds read this key and treat it as one club's money; re-interpreting it
     * as a pool would be a changed value under an unchanged key, which
     * `.context/shared/api-backwards-compatibility.md` counts as a break. So the pool is the new
     * key and this is maintained from it — see `LeagueSizeResolver::syncTvDeal()`.
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $tvDeal = null;

    /**
     * The league's whole TV money for a season, in pence, split equally across its clubs.
     *
     * THE AUTHORED VALUE. `tvDeal` is derived from it; this is what an operator sets. The split
     * is equal and includes NPC clubs, because the backend cannot pay a club anything — there
     * is no income loop here, the device runs the economy — so what it publishes is the pool
     * and the club count, and the app does the paying.
     *
     * NULLABLE, AND NULL IS THE INERT STATE THAT MAKES THIS SHIPPABLE. With no pool authored,
     * `tvDeal` is left exactly as it was and nothing derives anything: deploying the column is
     * a provable no-op. Deriving unconditionally could not be — `LeagueService::
     * generateLeaguesForCountry()` creates league rows BEFORE any club exists, so a fresh
     * country would divide by zero or publish its whole pool as one club's share.
     *
     * Base-year pence. The app applies inflation; nothing here is ever stored inflated.
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $tvPoolPence = null;

    /**
     * How many clubs this league's table holds. **0 means "work it out"**, not "no clubs".
     *
     * AUTHORED RATHER THAN COUNTED, because a `League` row is a template shared by every save
     * in its country and tier — England tier 8 had 147 player clubs pointing at one row on
     * 2026-11-12 — so there is nothing to count that means what a divisor needs to mean. The
     * full reasoning, including why a derived divisor would silently re-price TV money whenever
     * an operator clicked Generate, is on `LeagueSizeResolver`.
     *
     * Zero-means-derive is the same idiom as `starter_config.club_funding_minimum_pence` and
     * `max_enrichment_requests_per_user`: it is how a column ships without changing behaviour.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $slots = 0;

    #[ORM\Column(type: 'string', enumType: ReputationTier::class, nullable: true)]
    private ?ReputationTier $leagueReputationTier = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $prizeMoney = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $leaguePositionPot = null;

    /** How many sponsors should be sourced for this league during world cache generation. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sponsorCount = 0;

    /**
     * Per-league override for the position-pot taper, as a whole percent. NULL = use GameConfig.
     *
     * NULLABLE SO THAT "NOT OVERRIDDEN" IS EXPRESSIBLE, which `0` could not be: a taper of zero
     * means every finishing position pays the same, and that is a legitimate thing for an
     * operator to want. Resolved `league ?? gameConfig` at every publish site, so the key the
     * client reads keeps its current meaning and its current value for every league that has no
     * override — which is all of them until somebody sets one.
     */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $leaguePositionDecreasePercent = null;

    /**
     * The trophy this league awards, as the 16-bit trophy sprite's own
     * `TrophyConfig`: {style, finish, ribbons, ribbonPrimary, ribbonSecondary}.
     * Stored and served verbatim — a schema-agnostic passthrough, exactly like
     * Player::$appearance and NpcClub::$identity — and rendered by
     * composeTrophySvg() (public/assets/trophy-compositor.js) on the admin and
     * landing side, by TrophySprite in the app.
     *
     * Replaces the retired `trophyImage` slug + `trophyColour` enum pair, which
     * named one of 15 static black SVGs in public/images/trophies/ recoloured by
     * a CSS filter chain. Nullable: "this division awards no trophy" is a real
     * state — ClubSpotlightService still lists a title with no artwork rather
     * than dropping real silverware over a missing admin setting.
     *
     * @var array{style:string,finish:string,ribbons:bool,ribbonPrimary:string,ribbonSecondary:string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $trophy = null;

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

    public function getTvPoolPence(): ?int { return $this->tvPoolPence; }
    public function setTvPoolPence(?int $v): static { $this->tvPoolPence = ($v !== null ? max(0, $v) : null); return $this; }

    public function getSlots(): int { return $this->slots; }

    /**
     * Capped at 40. Not arbitrary: the widest real league here is 18 and the divisor is only
     * meaningful for a plausible table, so a typo of 1800 should not quietly make every club's
     * TV share a hundredth of what it should be. Floored at 0, which means "derive".
     */
    public function setSlots(int $v): static { $this->slots = max(0, min(40, $v)); return $this; }

    public function getLeaguePositionDecreasePercent(): ?int { return $this->leaguePositionDecreasePercent; }
    public function setLeaguePositionDecreasePercent(?int $v): static
    {
        $this->leaguePositionDecreasePercent = ($v !== null ? max(0, min(100, $v)) : null);

        return $this;
    }

    public function getSponsorCount(): int { return $this->sponsorCount; }
    public function setSponsorCount(int $v): static { $this->sponsorCount = max(0, $v); return $this; }

    /** @return array{style:string,finish:string,ribbons:bool,ribbonPrimary:string,ribbonSecondary:string}|null */
    public function getTrophy(): ?array { return $this->trophy; }
    /** @param array{style:string,finish:string,ribbons:bool,ribbonPrimary:string,ribbonSecondary:string}|null $v */
    public function setTrophy(?array $v): static { $this->trophy = $v; return $this; }

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
