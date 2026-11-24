<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

/**
 * The Morale / Motivation dials: defaults mirror the client's DEFAULT_GAME_CONFIG (so the
 * migration alone changes nothing in game), and every setter clamps to the range the client
 * engine can use.
 */
class GameConfigMoraleMotivationTest extends TestCase
{
    public function testDefaultsMirrorTheClient(): void
    {
        $c = new GameConfig();

        self::assertSame(65, $c->getMoraleNormalLevel());
        self::assertSame(0.15, $c->getMoraleDriftAboveNormalPerWeek());
        self::assertSame(0.05, $c->getMoraleDriftBelowNormalPerWeek());
        self::assertSame(0.12, $c->getMotivationReversionPerWeek());
        self::assertSame(1.6, $c->getMotivationGameTimeBonus());
        self::assertSame(0.75, $c->getMotivationGameTimeBonusFullShare());
        self::assertSame(5, $c->getMotivationTeamFormMatches());
        self::assertSame(1.5, $c->getMotivationTeamFormMaxBonus());
        self::assertSame(1.0, $c->getMotivationTeamFormMaxPenalty());
        self::assertTrue($c->isMotivationShareStartsAcrossSquad());
        self::assertSame(30, $c->getPlayerPromiseGrantMotivationMin());
        self::assertSame(40, $c->getPlayerPromiseGrantMotivationMax());
        self::assertSame(-40, $c->getGametimePromiseBrokenMotivationDelta());
        self::assertSame(-40, $c->getPlayerContractPromiseBrokenMotivationDelta());
        self::assertSame(
            ['star_player' => 1.0, 'important' => 1.0, 'first_team' => 1.0, 'squad_player' => 0.5, 'backup' => 0.0, 'prospect' => 0.0],
            $c->getMotivationGameTimePenaltyWeightByRole(),
        );
    }

    public function testDriftAboveNormalIsFasterThanBelowByDefault(): void
    {
        $c = new GameConfig();
        self::assertGreaterThan($c->getMoraleDriftBelowNormalPerWeek(), $c->getMoraleDriftAboveNormalPerWeek());
    }

    public function testSettersClamp(): void
    {
        $c = (new GameConfig())
            ->setMoraleNormalLevel(140)
            ->setMoraleDriftAboveNormalPerWeek(3.0)
            ->setMoraleDriftBelowNormalPerWeek(-1.0)
            ->setMotivationTeamFormMatches(0)
            ->setMotivationTeamFormNeutralPpg(1.0)
            ->setGametimePromiseBrokenMotivationDelta(25);

        self::assertSame(100, $c->getMoraleNormalLevel());
        self::assertSame(1.0, $c->getMoraleDriftAboveNormalPerWeek());
        self::assertSame(0.0, $c->getMoraleDriftBelowNormalPerWeek());
        self::assertSame(1, $c->getMotivationTeamFormMatches());
        self::assertSame(0.99, $c->getMotivationTeamFormNeutralPpg());
        self::assertSame(0, $c->getGametimePromiseBrokenMotivationDelta());
    }

    public function testPenaltyWeightsKeepAllSixRolesAndDropJunk(): void
    {
        $c = (new GameConfig())->setMotivationGameTimePenaltyWeightByRole([
            'squad_player' => '0.25',
            'backup'       => 7,
            'goalkeeper'   => 1,
        ]);

        $w = $c->getMotivationGameTimePenaltyWeightByRole();
        self::assertSame(['star_player', 'important', 'first_team', 'squad_player', 'backup', 'prospect'], array_keys($w));
        self::assertSame(0.25, $w['squad_player']);
        self::assertSame(1.0, $w['backup']);
        self::assertSame(1.0, $w['star_player']);
    }
}
