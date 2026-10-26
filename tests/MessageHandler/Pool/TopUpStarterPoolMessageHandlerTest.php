<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Pool;

use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Message\Pool\TopUpStarterPoolMessage;
use App\MessageHandler\Pool\TopUpStarterPoolMessageHandler;
use App\Repository\PlayerRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Service\MarketPoolService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The handler's whole job beyond "generate" is the target re-check: a chunk sized against
 * the stock the audit saw must not overshoot the floor once earlier chunks have landed.
 * That clamp is what makes a replayed or duplicated message harmless, so it is asserted
 * directly rather than left implied.
 */
class TopUpStarterPoolMessageHandlerTest extends TestCase
{
    private MarketPoolService&MockObject $pool;
    private PlayerRepository&Stub        $players;
    private StaffRepository&Stub         $staff;
    private ScoutRepository&Stub         $scouts;
    private TopUpStarterPoolMessageHandler $handler;

    protected function setUp(): void
    {
        $this->pool    = $this->createMock(MarketPoolService::class);
        $this->players = $this->createStub(PlayerRepository::class);
        $this->staff   = $this->createStub(StaffRepository::class);
        $this->scouts  = $this->createStub(ScoutRepository::class);

        $this->handler = new TopUpStarterPoolMessageHandler(
            $this->pool,
            $this->players,
            $this->staff,
            $this->scouts,
            new NullLogger(),
        );
    }

    public function testGeneratesTheFullChunkWhenTheFloorIsStillFarOff(): void
    {
        $this->players->method('countInPoolByNationality')->willReturn(100);

        $this->pool->expects(self::once())
            ->method('generatePlayers')
            ->with(100, RecruitmentSource::YOUTH_INTAKE, 'English')
            ->willReturn([]);

        ($this->handler)(new TopUpStarterPoolMessage('EN', 'English', TopUpStarterPoolMessage::KIND_PLAYERS, 100, 500));
    }

    public function testClampsTheChunkToWhatIsLeftBelowTheFloor(): void
    {
        $this->players->method('countInPoolByNationality')->willReturn(470);

        $this->pool->expects(self::once())
            ->method('generatePlayers')
            ->with(30, RecruitmentSource::YOUTH_INTAKE, 'English')
            ->willReturn([]);

        ($this->handler)(new TopUpStarterPoolMessage('EN', 'English', TopUpStarterPoolMessage::KIND_PLAYERS, 100, 500));
    }

    public function testGeneratesNothingOnceTheFloorIsMet(): void
    {
        // The redundant-delivery case: an earlier chunk (or an admin pressing Warm)
        // already got the pool there.
        $this->players->method('countInPoolByNationality')->willReturn(500);

        $this->pool->expects(self::never())->method('generatePlayers');

        ($this->handler)(new TopUpStarterPoolMessage('EN', 'English', TopUpStarterPoolMessage::KIND_PLAYERS, 100, 500));
    }

    public function testStaffTopUpCountsAndGeneratesForItsOwnRole(): void
    {
        $this->staff->method('countInPoolByNationalityAndRole')
            ->willReturnCallback(function (string $nationality, StaffRole $role): int {
                self::assertSame('English', $nationality);
                self::assertSame(StaffRole::DIRECTOR_OF_FOOTBALL, $role, 'A staff chunk must count the role it is about to generate.');

                return 10;
            });

        $this->pool->expects(self::once())
            ->method('generateStaffForRole')
            ->with(StaffRole::DIRECTOR_OF_FOOTBALL, 40, 'English')
            ->willReturn([]);

        ($this->handler)(new TopUpStarterPoolMessage(
            'EN',
            'English',
            TopUpStarterPoolMessage::KIND_STAFF,
            100,
            50,
            StaffRole::DIRECTOR_OF_FOOTBALL->value,
        ));
    }

    public function testScoutTopUpGeneratesScouts(): void
    {
        $this->scouts->method('countInPool')->willReturn(5);

        $this->pool->expects(self::once())
            ->method('generateScouts')
            ->with(45, 'English')
            ->willReturn([]);

        ($this->handler)(new TopUpStarterPoolMessage('EN', 'English', TopUpStarterPoolMessage::KIND_SCOUTS, 100, 50));
    }

    public function testAnUnknownKindFailsLoudlyRatherThanSilentlyDoingNothing(): void
    {
        $this->pool->expects(self::never())->method('generatePlayers');
        $this->expectException(\InvalidArgumentException::class);

        ($this->handler)(new TopUpStarterPoolMessage('EN', 'English', 'agents', 10, 50));
    }
}
