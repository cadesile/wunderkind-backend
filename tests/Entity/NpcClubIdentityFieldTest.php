<?php

namespace App\Tests\Entity;

use App\Entity\NpcClub;
use PHPUnit\Framework\TestCase;

class NpcClubIdentityFieldTest extends TestCase
{
    private const IDENTITY = [
        'home' => ['kit' => 'hoops', 'primary' => '#1f8a4c', 'secondary' => '#f4f3ee', 'shorts' => 'white', 'socks' => 'primary'],
        'away' => ['kit' => 'plain', 'primary' => '#1a1a1a', 'secondary' => '#f2c230', 'shorts' => 'black', 'socks' => 'black'],
        'badgeShape' => 'round', 'badgePattern' => 'stripes', 'badgeCentre' => 'initials',
        'initials' => 'AFC', 'badgeFill' => '#1b2a4a', 'badgeTrim' => '#f2c230', 'badgeSymbol' => '#f4f3ee',
    ];

    public function testDefaultsToNull(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);

        $this->assertNull($club->getIdentity());
    }

    public function testRoundTripsTheFullNestedShape(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);

        $club->setIdentity(self::IDENTITY);

        $this->assertSame(self::IDENTITY, $club->getIdentity());
    }

    public function testSetIdentityAcceptsNull(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);
        $club->setIdentity(self::IDENTITY);

        $club->setIdentity(null);

        $this->assertNull($club->getIdentity());
    }

    public function testSettingIdentitySyncsPrimaryAndSecondaryColorFromHomeKit(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);

        $club->setIdentity(self::IDENTITY);

        $this->assertSame('#1f8a4c', $club->getPrimaryColor());
        $this->assertSame('#f4f3ee', $club->getSecondaryColor());
    }

    public function testSettingIdentityToNullLeavesExistingColorsUntouched(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);
        $club->setIdentity(self::IDENTITY);

        $club->setIdentity(null);

        $this->assertSame('#1f8a4c', $club->getPrimaryColor());
        $this->assertSame('#f4f3ee', $club->getSecondaryColor());
    }

    public function testMalformedIdentityWithoutHomeKitDoesNotTouchColors(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#aaa', '#bbb', 50000, []);

        $club->setIdentity(['badgeShape' => 'shield']);

        $this->assertSame('#aaa', $club->getPrimaryColor());
        $this->assertSame('#bbb', $club->getSecondaryColor());
    }
}
