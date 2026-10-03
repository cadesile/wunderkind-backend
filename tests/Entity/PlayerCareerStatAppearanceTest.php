<?php

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\PlayerCareerStat;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Covers PlayerCareerStat::toFullAppearanceConfig() — kit color is determined from the
 * parent club (Club::$homeKitConfig), never stored on PlayerCareerStat itself.
 */
class PlayerCareerStatAppearanceTest extends TestCase
{
    private function buildStat(): PlayerCareerStat
    {
        $user = new User('owner@test.com');
        $club = new Club('Test FC', $user);

        return new PlayerCareerStat($club, 'player-1', 'Test Player');
    }

    public function testReturnsNullWhenNoAppearanceConfigReportedYet(): void
    {
        $stat = $this->buildStat();

        $this->assertNull($stat->toFullAppearanceConfig());
    }

    public function testMergesPersonalTraitsWithClubKitColors(): void
    {
        $stat = $this->buildStat();
        $stat->getClub()->setHomeKitConfig(['kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee', 'shorts' => 'black', 'socks' => 'primary']);

        $personalTraits = ['hair' => 'buzz', 'hairColor' => 'black', 'headband' => false, 'skin' => 's3', 'face' => 'neutral', 'facial' => 'none', 'lip' => 'rose'];
        $stat->setAppearanceConfig($personalTraits);

        $full = $stat->toFullAppearanceConfig();

        $this->assertSame([
            'hair' => 'buzz', 'hairColor' => 'black', 'headband' => false, 'skin' => 's3',
            'face' => 'neutral', 'facial' => 'none', 'lip' => 'rose',
            'kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee', 'shorts' => 'black', 'socks' => 'primary',
        ], $full);
    }

    public function testReturnsPersonalTraitsOnlyWhenClubHasNoKitIdentitySetYet(): void
    {
        $stat = $this->buildStat();
        // Club::$homeKitConfig starts null and is never auto-generated (see docs/api/club-kit-identity.md).
        $personalTraits = ['hair' => 'afro', 'hairColor' => 'ginger', 'headband' => true, 'skin' => 's1', 'face' => 'happy', 'facial' => 'stubble', 'lip' => 'peach'];
        $stat->setAppearanceConfig($personalTraits);

        $this->assertSame($personalTraits, $stat->toFullAppearanceConfig());
    }
}
