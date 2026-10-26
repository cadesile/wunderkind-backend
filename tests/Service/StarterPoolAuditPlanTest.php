<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Message\Pool\TopUpStarterPoolMessage;
use App\Service\StarterPoolAuditService;
use PHPUnit\Framework\TestCase;

/**
 * Covers StarterPoolAuditService::planFor() — the decision about what an unattended sweep
 * is allowed to queue. It is a pure static so these assertions are about precedence
 * rather than about what a mocked repository happened to return.
 *
 * The case that matters most is the one that queues NOTHING: under the default PoolConfig
 * (players generated at 12–13, first-team draw floored at 19) no amount of generation
 * produces an eligible player, and a cron that queued against that shortfall would
 * generate hundreds of rows an hour forever without moving the verdict.
 */
class StarterPoolAuditPlanTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function diagnosis(array $overrides = []): array
    {
        return array_merge([
            'country'           => 'EN',
            'countryLabel'      => 'England',
            'nationality'       => 'English',
            'verdict'           => 'degraded',
            'ability'           => ['min' => 40, 'max' => 60, 'source' => 'config'],
            'playersRequested'  => 18,
            'playersAvailable'  => 4,
            'bandHoldsOnlyKids' => false,
            'staff'             => [
                ['role' => 'manager', 'requested' => 1, 'native' => 0, 'available' => 9, 'shortfall' => 0, 'ok' => true],
            ],
            'scouts'            => ['requested' => 1, 'native' => 3, 'available' => 9, 'shortfall' => 0, 'ok' => true],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function native(array $overrides = []): array
    {
        return array_merge(['players' => 0, 'staff' => [], 'scouts' => 0], $overrides);
    }

    public function testAHealthyCountryQueuesNothing(): void
    {
        $plan = StarterPoolAuditService::planFor(
            $this->diagnosis(['verdict' => 'ok', 'playersAvailable' => 18]),
            $this->native(),
            generatedPlayerAgeMax: 24,
            firstTeamAgeMin: 19,
        );

        self::assertSame([], $plan['needs']);
        self::assertSame([], $plan['blockers']);
        self::assertSame(0, $plan['messages']);
    }

    public function testAgeLockedPlayerShortfallBecomesABlockerNotAQueue(): void
    {
        // The shipped default: PoolConfig generates 12–13 year olds, the draw wants 19+.
        $plan = StarterPoolAuditService::planFor(
            $this->diagnosis(),
            $this->native(),
            generatedPlayerAgeMax: 13,
            firstTeamAgeMin: 19,
        );

        self::assertSame([], $plan['needs'], 'Generating players that can never be drawn must not be queued.');
        self::assertCount(1, $plan['blockers']);
        self::assertStringContainsString('cannot be fixed by generating more', $plan['blockers'][0]);
    }

    public function testBandHoldingOnlyKidsBecomesABlockerEvenWhenGenerationCouldReachTheAgeFloor(): void
    {
        $plan = StarterPoolAuditService::planFor(
            $this->diagnosis(['bandHoldsOnlyKids' => true]),
            $this->native(),
            generatedPlayerAgeMax: 24,
            firstTeamAgeMin: 19,
        );

        self::assertSame([], $plan['needs']);
        self::assertStringContainsString('ability-band problem', $plan['blockers'][0]);
    }

    public function testPlayerShortfallQueuesChunksUpToTheFloor(): void
    {
        $plan = StarterPoolAuditService::planFor(
            $this->diagnosis(),
            $this->native(['players' => 260]),
            generatedPlayerAgeMax: 24,
            firstTeamAgeMin: 19,
            minimums: ['players' => 500],
            chunk: 100,
        );

        self::assertCount(1, $plan['needs']);
        $need = $plan['needs'][0];

        self::assertSame(TopUpStarterPoolMessage::KIND_PLAYERS, $need['kind']);
        self::assertSame(240, $need['deficit']);
        self::assertSame([100, 100, 40], $need['chunks'], 'The deficit must be split into bounded chunks.');
        self::assertSame(240, array_sum($need['chunks']));
        self::assertSame(3, $plan['messages']);
    }

    public function testEveryShortStaffRoleIsQueuedIncludingOnesPoolWarmNeverCovers(): void
    {
        // app:pool:warm only warms MANAGER/COACH/CHAIRMAN; the audit is driven by the
        // starter pack's own requirements, so these two are not a blind spot.
        $plan = StarterPoolAuditService::planFor(
            $this->diagnosis([
                'playersAvailable' => 18,
                'staff'            => [
                    ['role' => 'manager',              'requested' => 1, 'native' => 9, 'available' => 9, 'shortfall' => 0, 'ok' => true],
                    ['role' => 'director_of_football', 'requested' => 1, 'native' => 0, 'available' => 0, 'shortfall' => 1, 'ok' => false],
                    ['role' => 'facility_manager',     'requested' => 1, 'native' => 0, 'available' => 0, 'shortfall' => 1, 'ok' => false],
                ],
            ]),
            $this->native(['staff' => ['manager' => 9, 'director_of_football' => 0, 'facility_manager' => 20]]),
            generatedPlayerAgeMax: 24,
            firstTeamAgeMin: 19,
            minimums: ['staff' => 50],
            chunk: 100,
        );

        self::assertSame(
            ['director_of_football', 'facility_manager'],
            array_column($plan['needs'], 'role'),
        );
        self::assertSame(50, $plan['needs'][0]['deficit']);
        self::assertSame(30, $plan['needs'][1]['deficit'], 'An already part-stocked role tops up only the remainder.');
    }

    public function testScoutShortfallQueuesScouts(): void
    {
        $plan = StarterPoolAuditService::planFor(
            $this->diagnosis([
                'playersAvailable' => 18,
                'scouts'           => ['requested' => 1, 'native' => 0, 'available' => 0, 'shortfall' => 1, 'ok' => false],
            ]),
            $this->native(['scouts' => 12]),
            generatedPlayerAgeMax: 24,
            firstTeamAgeMin: 19,
            minimums: ['scouts' => 50],
        );

        self::assertCount(1, $plan['needs']);
        self::assertSame(TopUpStarterPoolMessage::KIND_SCOUTS, $plan['needs'][0]['kind']);
        self::assertSame(38, $plan['needs'][0]['deficit']);
    }

    public function testTargetNeverFallsBelowWhatTheStarterPackActuallyAsksFor(): void
    {
        // A floor configured lower than the requirement must not cap the top-up below it.
        $plan = StarterPoolAuditService::planFor(
            $this->diagnosis([
                'playersAvailable' => 18,
                'scouts'           => ['requested' => 30, 'native' => 0, 'available' => 0, 'shortfall' => 30, 'ok' => false],
            ]),
            $this->native(['scouts' => 0]),
            generatedPlayerAgeMax: 24,
            firstTeamAgeMin: 19,
            minimums: ['scouts' => 5],
        );

        self::assertSame(30, $plan['needs'][0]['target']);
    }

    public function testAShortfallThisNationalityCannotFixIsReportedRatherThanSilentlySkipped(): void
    {
        // Pool-wide the role is short, but this nationality is already at its floor —
        // another country's sweep owns it. Must not queue, must not look like a no-op.
        $plan = StarterPoolAuditService::planFor(
            $this->diagnosis([
                'playersAvailable' => 18,
                'staff'            => [
                    ['role' => 'chairman', 'requested' => 1, 'native' => 60, 'available' => 0, 'shortfall' => 1, 'ok' => false],
                ],
            ]),
            $this->native(['staff' => ['chairman' => 60]]),
            generatedPlayerAgeMax: 24,
            firstTeamAgeMin: 19,
            minimums: ['staff' => 50],
        );

        self::assertSame([], $plan['needs']);
        self::assertCount(1, $plan['blockers']);
        self::assertStringContainsString('already at or above every top-up floor', $plan['blockers'][0]);
    }

    public function testAnEnabledCountryWithNoNationalityIsReportedNotQueued(): void
    {
        $plan = StarterPoolAuditService::planFor(
            $this->diagnosis(['country' => 'ZZ', 'countryLabel' => 'ZZ', 'nationality' => null, 'verdict' => 'blocked']),
            $this->native(),
            generatedPlayerAgeMax: 24,
            firstTeamAgeMin: 19,
        );

        self::assertSame([], $plan['needs']);
        self::assertStringContainsString('not a known Country case', $plan['blockers'][0]);
    }
}
