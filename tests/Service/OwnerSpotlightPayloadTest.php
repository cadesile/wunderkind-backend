<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\ClubFacility;
use App\Entity\User;
use App\Repository\ClubFacilityRepository;
use App\Repository\ClubRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Facility\FacilityConfigBuilder;
use App\Service\OwnerSpotlightService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * The two payload fields the landing redesign added to the Rich List: a share of the
 * leader's total, and a stadium for the leader's card.
 *
 * Both are server-computed on purpose. The template cannot work out a share because it
 * only ever sees the owners it is rendering, and the stadium has to come from the same
 * builder the Club Spotlight uses or the two boards would draw the same club differently.
 */
class OwnerSpotlightPayloadTest extends TestCase
{
    /**
     * @param list<array{userId: string, balancePence: int}> $owners
     * @param array<string, array<string, int>>              $facilityLevelsByClub
     *
     * @return array<int, array<string, mixed>>
     */
    private function board(array $owners, array $clubsByOwner = [], array $facilityLevels = []): array
    {
        $ledger = $this->createStub(UserLedgerRepository::class);
        $ledger->method('findTopOwnersByBalance')->willReturn(array_map(
            static fn (array $o): array => $o + [
                'ownerName'        => 'Owner',
                'ownerNationality' => null,
                'ownerAppearance'  => null,
            ],
            $owners,
        ));

        $clubs = [];
        foreach ($clubsByOwner as $userId => $names) {
            foreach ($names as $name) {
                $clubs[] = $this->club($userId, $name);
            }
        }

        $clubRepo = $this->createStub(ClubRepository::class);
        $clubRepo->method('findByUserIds')->willReturn($clubs);

        $facilityRepo = $this->createStub(ClubFacilityRepository::class);
        $facilityRepo->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($facilityLevels): array {
                /** @var Club $club */
                $club   = $criteria['club'];
                $levels = $facilityLevels[$club->getName()] ?? [];

                return array_map(
                    static fn (string $slug): ClubFacility => new ClubFacility($club, $slug, $levels[$slug]),
                    array_keys($levels),
                );
            },
        );

        $service = new OwnerSpotlightService(
            $clubRepo,
            $ledger,
            new FacilityConfigBuilder($facilityRepo),
            $this->cache(),
        );

        return $service->getTopOwners();
    }

    private function cache(): CacheInterface
    {
        return new class(new ArrayAdapter()) implements CacheInterface {
            public function __construct(private readonly ArrayAdapter $inner) {}

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return $this->inner->get($key, $callback, $beta, $metadata);
            }

            public function delete(string $key): bool
            {
                return $this->inner->delete($key);
            }
        };
    }

    private function club(string $userId, string $name): Club
    {
        $user = new User($userId . '@owner.test');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, new \Symfony\Component\Uid\UuidV7());

        $club = new Club($name, $user);

        // findByUserIds() buckets on the owner's id, so the stub's clubs must agree
        // with the ledger rows they are meant to belong to.
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, \Symfony\Component\Uid\UuidV7::fromString($userId));

        return $club;
    }

    public function testTheLeaderIsAlwaysOneHundredPercent(): void
    {
        $id = '0193f2c6-0000-7000-8000-000000000001';

        $board = $this->board([['userId' => $id, 'balancePence' => 5_000_00]]);

        $this->assertSame(100.0, $board[0]['sharePct']);
    }

    public function testShareIsMeasuredAgainstTheLeaderNotTheBoardTotal(): void
    {
        $a = '0193f2c6-0000-7000-8000-00000000000a';
        $b = '0193f2c6-0000-7000-8000-00000000000b';

        $board = $this->board([
            ['userId' => $a, 'balancePence' => 1_000_00],
            ['userId' => $b, 'balancePence' => 250_00],
        ]);

        // 25% of the leader. Of the board TOTAL it would be 20%, which is the number
        // this would show if the denominator were wrong.
        $this->assertSame(25.0, $board[1]['sharePct']);
    }

    /** The ledger holds only client-reported draws, so an all-zero board is a real state. */
    public function testAnAllZeroBoardDoesNotDivideByZero(): void
    {
        $a = '0193f2c6-0000-7000-8000-00000000000c';

        $board = $this->board([['userId' => $a, 'balancePence' => 0]]);

        $this->assertSame(0.0, $board[0]['sharePct']);
    }

    public function testOnlyTheLeaderGetsAStadium(): void
    {
        $a = '0193f2c6-0000-7000-8000-00000000000d';
        $b = '0193f2c6-0000-7000-8000-00000000000e';

        $board = $this->board(
            [
                ['userId' => $a, 'balancePence' => 900_00],
                ['userId' => $b, 'balancePence' => 100_00],
            ],
            [$a => ['Leader FC'], $b => ['Runner Up FC']],
            ['Leader FC' => ['north_stand' => 3, 'club_shop' => 2]],
        );

        $this->assertIsArray($board[0]['stadiumConfig']);
        $this->assertSame(6, $board[0]['stadiumConfig']['north'], 'built through the shared builder, so levels are doubled');
        $this->assertTrue($board[0]['stadiumConfig']['shop']);

        $this->assertNull($board[1]['stadiumConfig'], 'four extra ClubFacility queries for cards that never draw one');
    }

    public function testAnOwnerWithNoClubsGetsNoStadiumRatherThanAnError(): void
    {
        $a = '0193f2c6-0000-7000-8000-00000000000f';

        $board = $this->board([['userId' => $a, 'balancePence' => 10_00]]);

        $this->assertNull($board[0]['stadiumConfig']);
    }
}
