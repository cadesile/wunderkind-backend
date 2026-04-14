<?php

namespace App\Tests\Entity;

use App\Entity\League;
use App\Entity\LeagueSponsor;
use App\Entity\Sponsor;
use App\Enum\CompanySize;
use App\Enum\ReputationTier;
use PHPUnit\Framework\TestCase;

class LeagueConfigFieldsTest extends TestCase
{
    public function testScalarFieldDefaults(): void
    {
        $league = new League('EN', 8, 'League 8');

        $this->assertNull($league->getPromotionSpots());
        $this->assertNull($league->getTvDeal());
        $this->assertNull($league->getLeagueReputationTier());
        $this->assertNull($league->getPrizeMoney());
        $this->assertNull($league->getLeaguePositionPot());
        $this->assertEmpty($league->getLeagueSponsors());
    }

    public function testScalarFieldSetters(): void
    {
        $league = new League('EN', 3, 'League 3');
        $league->setPromotionSpots(2);
        $league->setTvDeal(5000000);
        $league->setLeagueReputationTier(ReputationTier::NATIONAL);
        $league->setPrizeMoney(250000);
        $league->setLeaguePositionPot(400000);

        $this->assertSame(2,                        $league->getPromotionSpots());
        $this->assertSame(5000000,                  $league->getTvDeal());
        $this->assertSame(ReputationTier::NATIONAL,  $league->getLeagueReputationTier());
        $this->assertSame(250000,                   $league->getPrizeMoney());
        $this->assertSame(400000,                   $league->getLeaguePositionPot());
    }

    public function testAddAndRemoveSponsor(): void
    {
        $league  = new League('EN', 5, 'League 5');
        $sponsor = new Sponsor('Test Corp');
        $sponsor->setSize(CompanySize::MEDIUM);

        $league->addSponsor($sponsor);
        $this->assertCount(1, $league->getLeagueSponsors());
        $this->assertSame($sponsor, $league->getLeagueSponsors()->first()->getSponsor());

        // Adding same sponsor twice is a no-op
        $league->addSponsor($sponsor);
        $this->assertCount(1, $league->getLeagueSponsors());

        $league->removeSponsor($sponsor);
        $this->assertCount(0, $league->getLeagueSponsors());
    }

    public function testLeagueSponsorRolledValue(): void
    {
        $league  = new League('EN', 5, 'League 5');
        $sponsor = new Sponsor('Test Corp');
        $league->addSponsor($sponsor);

        $ls = $league->getLeagueSponsors()->first();
        $this->assertInstanceOf(LeagueSponsor::class, $ls);
        $this->assertSame(0, $ls->getRolledValue());

        $ls->setRolledValue(75000);
        $this->assertSame(75000, $ls->getRolledValue());
    }
}
