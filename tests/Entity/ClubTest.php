<?php

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\User;
use App\Enum\Formation;
use PHPUnit\Framework\TestCase;

class ClubTest extends TestCase
{
    public function testClubInstantiation(): void
    {
        $user = $this->createStub(User::class);
        $club = new Club('Test FC', $user);
        $this->assertInstanceOf(Club::class, $club);
        $this->assertSame('Test FC', $club->getName());
        $this->assertSame($user, $club->getUser());
        $this->assertSame(Formation::F_442, $club->getFormation());
    }

    public function testFormationSetter(): void
    {
        $user = $this->createStub(User::class);
        $club = new Club('Test FC', $user);
        $club->setFormation(Formation::F_433);
        $this->assertSame(Formation::F_433, $club->getFormation());
    }

    public function testKitAndBadgeConfigDefaultToNullAndAreStoredVerbatim(): void
    {
        $user = $this->createStub(User::class);
        $club = new Club('Test FC', $user);

        $this->assertNull($club->getHomeKitConfig());
        $this->assertNull($club->getAwayKitConfig());
        $this->assertNull($club->getBadgeConfig());

        $home = ['kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee', 'shorts' => 'black', 'socks' => 'primary'];
        $away = ['kit' => 'hoops', 'primary' => '#000000', 'secondary' => '#ffffff', 'shorts' => 'white', 'socks' => 'secondary'];
        $badge = ['badgeShape' => 'shield', 'badgePattern' => 'plain', 'badgeCentre' => 'initials', 'initials' => 'TFC', 'badgeFill' => '#c8202f', 'badgeTrim' => '#f4f3ee', 'badgeSymbol' => '#f2c230'];

        $club->setHomeKitConfig($home);
        $club->setAwayKitConfig($away);
        $club->setBadgeConfig($badge);

        $this->assertSame($home, $club->getHomeKitConfig());
        $this->assertSame($away, $club->getAwayKitConfig());
        $this->assertSame($badge, $club->getBadgeConfig());
    }
}
