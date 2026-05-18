<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'league_sponsor_income')]
class LeagueSponsor
{
    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'leagueSponsors')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private League $league;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Sponsor $sponsor;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $rolledValue = 0;

    public function __construct(League $league, Sponsor $sponsor, int $rolledValue = 0)
    {
        $this->league      = $league;
        $this->sponsor     = $sponsor;
        $this->rolledValue = $rolledValue;
    }

    public function getLeague(): League { return $this->league; }
    public function getSponsor(): Sponsor { return $this->sponsor; }
    public function getRolledValue(): int { return $this->rolledValue; }
    public function setRolledValue(int $v): static { $this->rolledValue = $v; return $this; }
}
