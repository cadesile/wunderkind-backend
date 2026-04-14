<?php

namespace App\Tests\Entity;

use App\Entity\League;
use PHPUnit\Framework\TestCase;

class LeagueTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $league = new League('EN', 3, 'League 3');

        $this->assertSame('EN', $league->getCountry());
        $this->assertSame(3, $league->getTier());
        $this->assertSame('League 3', $league->getName());
        $this->assertNotNull($league->getId());
        $this->assertNotNull($league->getCreatedAt());
    }

    public function testSetters(): void
    {
        $league = new League('EN', 1, 'Premier League');
        $league->setName('Top Flight');
        $league->setCountry('ES');
        $league->setTier(2);

        $this->assertSame('Top Flight', $league->getName());
        $this->assertSame('ES', $league->getCountry());
        $this->assertSame(2, $league->getTier());
    }
}
