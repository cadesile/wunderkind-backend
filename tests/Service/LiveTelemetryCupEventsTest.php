<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LiveTelemetryService;
use PHPUnit\Framework\TestCase;

/**
 * The feed's tenth source: `CUP // WINNER` lines.
 *
 * Pure, like every other build* method on LiveTelemetryService — the DB walk that produces
 * these rows lives in CupTelemetryService::recentWinnerRows(), so this needs no kernel.
 */
class LiveTelemetryCupEventsTest extends TestCase
{
    private const AT = '2026-05-01 18:00:00';

    public function testAFinalWonInNinetyMinutesReadsAsAPlainScoreline(): void
    {
        $event = $this->buildOne();

        self::assertSame('CUP', $event['category']);
        self::assertSame('[CUP // WINNER]', $event['categoryLabel']);
        self::assertSame('Away Winners FC lifted the Spring Cup, 3 – 0.', $event['text']);
    }

    public function testExtraTimeIsNamedInTheLine(): void
    {
        self::assertSame(
            'Away Winners FC lifted the Spring Cup, 2 – 1 after extra time.',
            $this->buildOne(['winnerScore' => 2, 'loserScore' => 1, 'wentToExtraTime' => true])['text'],
        );
    }

    /**
     * The case the wording exists for: both sides scored the same, so the scoreline alone
     * does not say who won and the shootout has to.
     */
    public function testAShootoutCarriesBothScoresWithTheWinnersFirst(): void
    {
        $event = $this->buildOne([
            'winnerScore'     => 1,
            'loserScore'      => 1,
            'wentToExtraTime' => true,
            'wentToPenalties' => true,
            'penaltyWinner'   => 5,
            'penaltyLoser'    => 3,
        ]);

        self::assertSame(
            'Away Winners FC lifted the Spring Cup, 1 – 1 after extra time (5 – 3 on penalties).',
            $event['text'],
        );
    }

    /**
     * The trophy sprite and the prize are the two things that make a cup line look like a
     * cup line. Both have to survive mergeEventsByRecency(), which rebuilds every event
     * key-by-key from a whitelist and silently drops anything not on it.
     */
    public function testCupWinsCarryTheirTrophyThroughTheMerge(): void
    {
        $merged = LiveTelemetryService::mergeEventsByRecency(
            LiveTelemetryService::buildCupEvents([$this->row()], new \DateTimeImmutable(self::AT)),
        );

        self::assertSame('cup', $merged[0]['trophy']['style'], 'the whitelist must carry trophy, or the sprite never draws');
        self::assertSame('£5,000', $merged[0]['amountExact']);
        self::assertSame('Away Winners FC', $merged[0]['club']);
        self::assertSame('club-uuid-1', $merged[0]['clubId'], 'the boardroom dossier keys off this, never the name');
        self::assertSame(['cupName' => 'Spring Cup'], $merged[0]['meta']);
    }

    /** A cup with no prize configured reports no figure rather than "£0". */
    public function testACupWithNoPrizeReportsNoFigure(): void
    {
        self::assertNull($this->buildOne(['prizePence' => 0])['amountExact']);
    }

    /** Every other category must stay free of the new key. */
    public function testNonCupEventsCarryANullTrophy(): void
    {
        $ledger = LiveTelemetryService::buildLedgerEvents([
            ['serverTimestamp' => new \DateTimeImmutable(self::AT), 'clubName' => 'Ledger Club', 'payload' => ['ledger' => [
                ['category' => 'wages', 'amount' => -50000, 'description' => 'Week 1 payroll'],
            ]]],
        ], new \DateTimeImmutable(self::AT), 5);

        self::assertNull($ledger[0]['trophy']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function buildOne(array $overrides = []): array
    {
        return LiveTelemetryService::buildCupEvents(
            [$this->row($overrides)],
            new \DateTimeImmutable(self::AT),
        )[0];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'clubName'  => 'Away Winners FC',
            'clubId'    => 'club-uuid-1',
            'clubBadge' => ['home' => null, 'away' => null, 'badge' => null],
            'trophy'    => ['style' => 'cup', 'finish' => 'gold', 'ribbons' => true, 'ribbonPrimary' => '#112233', 'ribbonSecondary' => '#445566'],
            'cupName'   => 'Spring Cup',
            'winnerScore' => 3,
            'loserScore'  => 0,
            'wentToExtraTime' => false,
            'wentToPenalties' => false,
            'penaltyWinner'   => null,
            'penaltyLoser'    => null,
            'prizePence'      => 500000,
            'completedAt'     => new \DateTimeImmutable(self::AT),
        ], $overrides);
    }
}
