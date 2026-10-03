<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\SyncRequest;
use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\PlayerCareerStat;
use App\Entity\StaffCareerProfile;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Service\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Covers sync v2's two additive fields: playerStats[].appearanceConfig (personal-traits-only,
 * see PlayerCareerStat::$appearanceConfig) and the new top-level staffStats[] (identity +
 * avatar config only, no stats concept, see StaffCareerProfile). Both must preserve an
 * existing stored config when a sync omits the key entirely (older client), while still
 * allowing an explicit `null` to clear it.
 */
class SyncServicePlayerStaffStatsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testPlayerStatsAppearanceConfigIsStoredAndPreservedWhenOmittedThenClearedExplicitly(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $appearanceConfig = ['hair' => 'buzz', 'hairColor' => 'black', 'headband' => false, 'skin' => 's3', 'face' => 'neutral', 'facial' => 'none', 'lip' => 'rose'];

        $sync = self::getContainer()->get(SyncService::class);

        // Sync 1: sends appearanceConfig.
        $first                  = new SyncRequest();
        $first->clubId          = (string) $club->getId();
        $first->weekNumber      = 1;
        $first->clientTimestamp = '2026-10-03T00:00:00+00:00';
        $first->playerStats     = [[
            'playerId'         => 'player-1',
            'playerName'       => 'Test Player',
            'appearances'      => 3,
            'goals'            => 1,
            'assists'          => 0,
            'averageRating'    => 7.1,
            'appearanceConfig' => $appearanceConfig,
        ]];
        $sync->process($user, $first);

        $stat = $this->em->getRepository(PlayerCareerStat::class)->findOneBy(['club' => $club, 'playerId' => 'player-1']);
        $this->cleanup[] = $stat;
        $this->assertSame($appearanceConfig, $stat->getAppearanceConfig());

        // Sync 2: omits appearanceConfig entirely — an older client. Must NOT wipe it out.
        $second                  = new SyncRequest();
        $second->clubId          = (string) $club->getId();
        $second->weekNumber      = 2;
        $second->clientTimestamp = '2026-10-03T01:00:00+00:00';
        $second->playerStats     = [[
            'playerId'      => 'player-1',
            'playerName'    => 'Test Player',
            'appearances'   => 4,
            'goals'         => 1,
            'assists'       => 1,
            'averageRating' => 7.3,
        ]];
        $sync->process($user, $second);

        $this->em->refresh($stat);
        $this->assertSame($appearanceConfig, $stat->getAppearanceConfig(), 'Omitting appearanceConfig must preserve the previously-stored value');
        $this->assertSame(4, $stat->getAppearances(), 'Stat fields still update normally');

        // Sync 3: explicitly sends null — must clear it.
        $third                  = new SyncRequest();
        $third->clubId          = (string) $club->getId();
        $third->weekNumber      = 3;
        $third->clientTimestamp = '2026-10-03T02:00:00+00:00';
        $third->playerStats     = [[
            'playerId'         => 'player-1',
            'playerName'       => 'Test Player',
            'appearances'      => 5,
            'goals'            => 1,
            'assists'          => 1,
            'averageRating'    => 7.0,
            'appearanceConfig' => null,
        ]];
        $sync->process($user, $third);

        $this->em->refresh($stat);
        $this->assertNull($stat->getAppearanceConfig(), 'Explicit null must clear a previously-stored config');

        $this->cleanup = array_merge($this->cleanup, $this->em->getRepository(SyncRecord::class)->findBy(['club' => $club]));
        $this->cleanupLeaderboardEntriesFor($club);
    }

    public function testStaffStatsUpsertsByStaffIdWithAppearanceConfigAndPreservesOmittedConfig(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $appearanceConfig = [
            'hair' => 'crop', 'hairColor' => 'brown', 'headband' => false, 'skin' => 's2',
            'face' => 'happy', 'facial' => 'stubble', 'lip' => 'peach',
            'primary' => '#c8202f', 'secondary' => '#ffffff', 'outfit' => 'tracksuit',
            'trousers' => 'primary', 'glasses' => true,
        ];

        $sync = self::getContainer()->get(SyncService::class);

        $first                  = new SyncRequest();
        $first->clubId          = (string) $club->getId();
        $first->weekNumber      = 1;
        $first->clientTimestamp = '2026-10-03T00:00:00+00:00';
        $first->staffStats      = [[
            'staffId'          => 'staff-1',
            'staffName'        => 'Coach Smith',
            'staffRole'        => 'coach',
            'appearanceConfig' => $appearanceConfig,
        ]];
        $sync->process($user, $first);

        $profile = $this->em->getRepository(StaffCareerProfile::class)->findOneBy(['club' => $club, 'staffId' => 'staff-1']);
        $this->cleanup[] = $profile;

        $this->assertNotNull($profile);
        $this->assertSame('Coach Smith', $profile->getStaffName());
        $this->assertSame('coach', $profile->getStaffRole());
        $this->assertSame($appearanceConfig, $profile->getAppearanceConfig());

        // Also covers a staffRole the backend's StaffRole enum doesn't even have (scout) —
        // must not be rejected or coerced.
        $second                  = new SyncRequest();
        $second->clubId          = (string) $club->getId();
        $second->weekNumber      = 2;
        $second->clientTimestamp = '2026-10-03T01:00:00+00:00';
        $second->staffStats      = [
            ['staffId' => 'staff-1', 'staffName' => 'Coach Smith', 'staffRole' => 'coach'],
            ['staffId' => 'staff-2', 'staffName' => 'Scout Jones', 'staffRole' => 'scout'],
        ];
        $sync->process($user, $second);

        $this->em->refresh($profile);
        $this->assertSame($appearanceConfig, $profile->getAppearanceConfig(), 'Omitting appearanceConfig must preserve the previously-stored value');

        $secondProfile = $this->em->getRepository(StaffCareerProfile::class)->findOneBy(['club' => $club, 'staffId' => 'staff-2']);
        $this->cleanup[] = $secondProfile;
        $this->assertNotNull($secondProfile);
        $this->assertSame('scout', $secondProfile->getStaffRole());
        $this->assertNull($secondProfile->getAppearanceConfig());

        $this->cleanup = array_merge($this->cleanup, $this->em->getRepository(SyncRecord::class)->findBy(['club' => $club]));
        $this->cleanupLeaderboardEntriesFor($club);
    }

    private function cleanupLeaderboardEntriesFor(Club $club): void
    {
        foreach ($this->em->getRepository(LeaderboardEntry::class)->findBy(['club' => $club]) as $entry) {
            $this->cleanup[] = $entry;
        }
    }

    /** @return array{0: User, 1: Club} */
    private function persistUserAndClub(): array
    {
        $user = new User(bin2hex(random_bytes(8)) . '@syncstats.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->cleanup[] = $user;

        $club = new Club('Sync Stats FC', $user);
        $this->em->persist($club);
        $this->cleanup[] = $club;

        $this->em->flush();

        return [$user, $club];
    }
}
