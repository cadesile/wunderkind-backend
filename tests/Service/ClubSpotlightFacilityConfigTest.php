<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\ClubFacility;
use App\Entity\User;
use App\Repository\ClubFacilityRepository;
use App\Repository\ClubSpotlightRepository;
use App\Repository\LeagueRepository;
use App\Repository\MatchResultRepository;
use App\Repository\PlayerCareerStatRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\SyncRecordRepository;
use App\Repository\TransferRepository;
use App\Repository\UserLedgerRepository;
use App\Service\ClubSpotlightService;
use App\Service\Facility\FacilitySpriteMap;
use App\Service\InboxService;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * buildFacilityConfigs() — the four-site payload the redesigned landing page draws from.
 *
 * Every assertion here is really "we overrode the demo value". Each DEFAULT_*_CONFIG in the
 * sprite drop is the web builder's demo config with facilities switched on at mid-range
 * levels, and the page merges our config over it — so a key we omit does not read as
 * "unset", it reads as "draw someone else's training ground".
 */
class ClubSpotlightFacilityConfigTest extends TestCase
{
    /**
     * @param array<string,int> $facilityLevels
     *
     * @return array<string, array<string, mixed>>
     */
    private function facilities(array $facilityLevels, ?array $homeKitConfig = null): array
    {
        $user = new User('owner@facilities.test');
        $club = new Club('Test FC', $user);
        if ($homeKitConfig !== null) {
            $club->setHomeKitConfig($homeKitConfig);
        }

        $facilityRepo = $this->createStub(ClubFacilityRepository::class);
        $facilityRepo->method('findBy')->willReturn(array_map(
            static fn (string $slug): ClubFacility => new ClubFacility($club, $slug, $facilityLevels[$slug]),
            array_keys($facilityLevels),
        ));

        $service = new ClubSpotlightService(
            $this->createStub(SyncRecordRepository::class),
            $facilityRepo,
            $this->createStub(ClubSpotlightRepository::class),
            $this->createStub(MatchResultRepository::class),
            $this->createStub(TransferRepository::class),
            $this->createStub(PlayerCareerStatRepository::class),
            $this->createStub(UserLedgerRepository::class),
            $this->createStub(SeasonRecordRepository::class),
            $this->createStub(LeagueRepository::class),
            $this->createStub(InboxService::class),
            $this->createStub(PushNotificationService::class),
            $this->createStub(EntityManagerInterface::class),
        );

        return (new \ReflectionMethod($service, 'buildFacilityConfigs'))->invoke($service, $club, []);
    }

    /** @return array<string,int> every seeded facility at the given level */
    private function allSlugsAt(int $level): array
    {
        return array_fill_keys(FacilitySpriteMap::knownSlugs(), $level);
    }

    public function testAllFourSitesArePresent(): void
    {
        $sites = array_keys($this->facilities([]));
        sort($sites);

        $this->assertSame(['medical', 'scouting', 'stadium', 'training'], $sites);
    }

    /**
     * A club with nothing built is the state every new club starts in, and the case where
     * demo defaults leaking would be most obvious — a brand-new club showing a full
     * training complex.
     */
    public function testAClubWithNoFacilitiesSwitchesEveryoneOff(): void
    {
        $configs = $this->facilities([]);

        foreach (['training', 'medical', 'scouting'] as $site) {
            foreach (FacilitySpriteMap::SITES[$site] as $spriteId) {
                $this->assertFalse($configs[$site][$spriteId], sprintf('%s.%s must be off', $site, $spriteId));
                $this->assertSame(1, $configs[$site][$spriteId . 'Level'], 'unbuilt still renders a plot, floored at 1');
            }
        }
    }

    public function testBuiltFacilitiesAreSwitchedOnAndDoubled(): void
    {
        $configs = $this->facilities(['training_pitch' => 3, 'strength_suite' => 1, 'physio_clinic' => 2]);

        $this->assertTrue($configs['training']['pitches']);
        $this->assertSame(6, $configs['training']['pitchesLevel'], 'template 1-5 doubles onto the sprite 1-10 scale');
        $this->assertTrue($configs['training']['gym']);
        $this->assertSame(2, $configs['training']['gymLevel']);
        $this->assertTrue($configs['medical']['physio']);
        $this->assertSame(4, $configs['medical']['physioLevel']);

        // Untouched facilities on the same site stay explicitly off.
        $this->assertFalse($configs['training']['techzone']);
        $this->assertFalse($configs['medical']['imaging']);
    }

    /**
     * club_facility.level comes from the client's sync payload and is never validated
     * against the template's 1-5 range — live rows reach 11. Doubled that is 22, past the
     * end of a sprite size table that stops at 10.
     */
    public function testLevelsAboveTheTemplateRangeAreClampedToTheSpriteMaximum(): void
    {
        $configs = $this->facilities(['training_pitch' => 11, 'physio_clinic' => 7, 'scouting_center' => 5]);

        $this->assertSame(10, $configs['training']['pitchesLevel']);
        $this->assertSame(10, $configs['medical']['physioLevel']);
        $this->assertSame(10, $configs['scouting']['hqLevel']);
    }

    /** A club with all 22 maxed must render without exception and with nothing left off. */
    public function testAClubWithEveryFacilityMaxedRenders(): void
    {
        $configs = $this->facilities($this->allSlugsAt(5));

        foreach (['training', 'medical', 'scouting'] as $site) {
            foreach (FacilitySpriteMap::SITES[$site] as $spriteId) {
                $this->assertTrue($configs[$site][$spriteId]);
                $this->assertSame(10, $configs[$site][$spriteId . 'Level']);
            }
        }

        $this->assertTrue($configs['stadium']['shop']);
        $this->assertSame(10, $configs['stadium']['north']);
    }

    /**
     * club_facility stores whatever the client sends. Live data holds five slugs with no
     * FacilityTemplate at all, so an unmapped slug is expected input, not an error.
     */
    public function testUnknownSlugsAreIgnoredRatherThanBreakingTheConfig(): void
    {
        $configs = $this->facilities([
            'training_pitch'    => 2,
            'kinetic_lab'       => 4,
            'zenith_sanctuary'  => 5,
            'neuro_shed'        => 1,
        ]);

        $this->assertTrue($configs['training']['pitches']);
        $this->assertArrayNotHasKey('kinetic_lab', $configs['training']);
        $this->assertArrayNotHasKey('zenith_sanctuary', $configs['medical']);
    }

    /** The backend has no notion of an upgrade in progress, so it must not claim one. */
    public function testConstructionIsNeverClaimed(): void
    {
        $configs = $this->facilities($this->allSlugsAt(3));

        foreach (['training', 'medical', 'scouting'] as $site) {
            foreach (FacilitySpriteMap::SITES[$site] as $spriteId) {
                $this->assertFalse($configs[$site][$spriteId . 'Construction']);
            }
        }
    }

    public function testAccentComesFromTheHomeKitPrimary(): void
    {
        $configs = $this->facilities(['training_pitch' => 1], ['primary' => '#003090']);

        $this->assertSame('#003090', $configs['training']['accent']);
        $this->assertSame('#003090', $configs['scouting']['accent']);
    }

    /** homeKitConfig is client-supplied and never validated, so a junk colour must not ship. */
    public function testANonHexAccentIsLeftToTheSpriteDefault(): void
    {
        $configs = $this->facilities(['training_pitch' => 1], ['primary' => 'red']);

        $this->assertArrayNotHasKey('accent', $configs['training']);
    }
}
