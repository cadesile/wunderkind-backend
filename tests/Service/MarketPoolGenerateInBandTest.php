<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Agent;
use App\Entity\Player;
use App\Entity\PoolConfig;
use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use App\Repository\AgentRepository;
use App\Repository\InvestorRepository;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\SponsorRepository;
use App\Repository\StaffRepository;
use App\Entity\GameConfig;
use App\Entity\StarterConfig;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\WageModelResolver;
use App\Service\MarketPoolService;
use App\Service\NameGeneratorService;
use App\Service\PlayerGenerationService;
use App\Service\ScoutGenerationService;
use App\Service\StaffGenerationService;
use App\Service\WorldPackSnapshotBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * generatePlayersInBand() is the starter pack's half of the world pack's generation call:
 * PlayerGenerationService with a tier's ability band and the first-team age range. These
 * tests pin the two things that make it worth having over the flat generatePlayers().
 */
final class MarketPoolGenerateInBandTest extends TestCase
{
    /** @var list<array{0: PlayerPosition, 1: RecruitmentSource, 2: ?string, 3: ?array, 4: ?array}> */
    private array $calls = [];

    /** @var list<Player> */
    private array $persisted = [];

    /**
     * THE POINT OF THE WHOLE EXERCISE. The flat warm calls
     * `generate($position, $source, $nationality)` and nothing else, so generated players
     * aim at no tier — which is why topping the pool up could never fill a tier band that
     * was empty. This asserts the band and the age range reach the generator on every draw,
     * because a call that silently drops either one is indistinguishable from the old
     * behaviour at the database.
     */
    public function testTheBandAndAgeRangeReachTheGeneratorOnEveryDraw(): void
    {
        $band = ['min' => 10, 'max' => 25];
        $ages = ['min' => 19, 'max' => 33];

        $service = $this->service(abilities: [12, 18, 24]);
        $service->generatePlayersInBand(3, PlayerPosition::GOALKEEPER, 'English', $band, $ages);

        $this->assertCount(3, $this->calls);
        foreach ($this->calls as $call) {
            $this->assertSame(PlayerPosition::GOALKEEPER, $call[0]);
            $this->assertSame('English', $call[2]);
            $this->assertSame($band, $call[3], 'the tier band must be passed as the potential range');
            $this->assertSame($ages, $call[4], 'the first-team age range must be passed too');
        }
    }

    /**
     * The band is a POTENTIAL range and the starter draw filters on the RESULT
     * (`currentAbility BETWEEN min AND max`), so a draw that lands below the band is
     * worthless to the caller. Out-of-band draws are retried and discarded before persist()
     * — if they were kept, the funnel would stay short while the pool grew, which is
     * precisely the failure this work exists to end.
     */
    public function testOutOfBandDrawsAreRetriedAndNeverPersisted(): void
    {
        // Two misses (one low, one high) then a hit, for one requested player.
        $service = $this->service(abilities: [3, 40, 17]);

        $result = $service->generatePlayersInBand(1, PlayerPosition::MIDFIELDER, 'English', ['min' => 10, 'max' => 25], ['min' => 19, 'max' => 33]);

        $this->assertCount(1, $result['players']);
        $this->assertSame(3, $result['attempts'], 'both misses should be counted as draws');
        $this->assertCount(1, $this->persisted, 'only the in-band player may reach the database');
        $this->assertSame(17, $this->persisted[0]->getCurrentAbility());
        $this->assertSame(PlayerStatus::ACTIVE, $this->persisted[0]->getStatus());
    }

    /**
     * A band nobody can hit must come back SHORT rather than silently filled with
     * near-misses. The caller reports the shortfall, which is how an unreachable band
     * becomes visible to an operator instead of looking like a slow pool.
     */
    public function testAnUnhittableBandReturnsFewerPlayersThanAskedFor(): void
    {
        $service = $this->service(abilities: array_fill(0, 200, 1));

        $result = $service->generatePlayersInBand(2, PlayerPosition::DEFENDER, 'English', ['min' => 40, 'max' => 90], ['min' => 19, 'max' => 33], attemptsPerPlayer: 5);

        $this->assertSame([], $result['players']);
        $this->assertSame(10, $result['attempts'], '5 attempts per player, for 2 players');
        $this->assertSame([], $this->persisted);
    }

    /** An inverted band is clamped rather than matching nothing for ever. */
    public function testAnInvertedBandIsClampedToItsFloor(): void
    {
        $service = $this->service(abilities: [12]);

        $result = $service->generatePlayersInBand(1, PlayerPosition::ATTACKER, 'English', ['min' => 12, 'max' => 5], ['min' => 19, 'max' => 33]);

        $this->assertCount(1, $result['players']);
    }

    /**
     * @param list<int> $abilities the currentAbility each successive draw comes back with
     */
    private function service(array $abilities): MarketPoolService
    {
        $this->calls     = [];
        $this->persisted = [];

        $queue = $abilities;

        $playerGen = $this->createStub(PlayerGenerationService::class);
        $playerGen->method('generate')->willReturnCallback(
            function (PlayerPosition $position, RecruitmentSource $source, ?string $nationality = null, ?array $potentialRange = null, ?array $ageRange = null) use (&$queue): Player {
                $this->calls[] = [$position, $source, $nationality, $potentialRange, $ageRange];
                $ability = (int) (array_shift($queue) ?? 1);

                return new Player(
                    firstName:         'Test',
                    lastName:          'Player',
                    dateOfBirth:       new \DateTimeImmutable('-25 years'),
                    nationality:       $nationality ?? 'English',
                    position:          $position,
                    recruitmentSource: $source,
                    potential:         max($ability, 50),
                    currentAbility:    $ability,
                );
            }
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Player) {
                $this->persisted[] = $entity;
            }
        });

        $poolConfigRepo = $this->createStub(PoolConfigRepository::class);
        $poolConfigRepo->method('getConfig')->willReturn(new PoolConfig());

        $agentRepo = $this->createStub(AgentRepository::class);
        $agentRepo->method('findAll')->willReturn([new Agent('Test Agency')]);

        $names = $this->createStub(NameGeneratorService::class);
        $names->method('generateFirstName')->willReturn('Guardian');
        $names->method('generateLastName')->willReturn('Player');

        // WageModelResolver is final, so this is the real one over stubbed config — which
        // is fine here: pool rows are priced legacy and country-less either way, and what
        // this test is about is which players get persisted, not what they cost.
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new GameConfig());

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn(new StarterConfig());

        $profileRepo = $this->createStub(CountryProfileRepository::class);
        $profileRepo->method('findByCode')->willReturn(null);
        $profileRepo->method('findEconomyBaseline')->willReturn(null);

        $wageModel = new WageModelResolver(
            $gameConfigRepo,
            new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profileRepo),
            new \App\Service\Economy\TierBandScaleResolver($starterRepo),
        );

        return new MarketPoolService(
            $em,
            $this->createStub(PlayerRepository::class),
            $this->createStub(StaffRepository::class),
            $this->createStub(ScoutRepository::class),
            $agentRepo,
            $this->createStub(SponsorRepository::class),
            $this->createStub(InvestorRepository::class),
            $names,
            $poolConfigRepo,
            $playerGen,
            $this->createStub(WorldPackSnapshotBuilder::class),
            $this->createStub(StaffGenerationService::class),
            $this->createStub(ScoutGenerationService::class),
            $wageModel,
        );
    }
}
