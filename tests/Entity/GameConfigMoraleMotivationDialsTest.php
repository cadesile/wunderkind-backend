<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The second batch of Morale / Motivation dials (effects on XP and matches, kept-promise and
 * contract-breach deltas, clique bleed, staff result morale, manager motivation drift).
 *
 * Each one must (a) default to the client's DEFAULT_GAME_CONFIG value, so the migration alone
 * changes nothing in game, (b) clamp to its range, (c) be served by GET /api/game-config under
 * the client's key and (d) be read by the admin save handler. (d) plus GameConfigScreenTest means
 * every dial is also rendered on the Game config page.
 */
class GameConfigMoraleMotivationDialsTest extends TestCase
{
    /** key => [client default, min, max] */
    private const DIALS = [
        'moraleDevelopmentThresholdFull'         => [60, 0, 100],
        'moraleDevelopmentThresholdMid'          => [35, 0, 100],
        'moraleDevelopmentMultiplierMid'         => [0.6, 0.0, 2.0],
        'moraleDevelopmentMultiplierLow'         => [0.2, 0.0, 2.0],
        'motivationHighThreshold'                => [75, 0, 100],
        'motivationMidThreshold'                 => [40, 0, 100],
        'motivationHighMultiplier'               => [1.2, 0.0, 3.0],
        'motivationMidMultiplier'                => [1.0, 0.0, 3.0],
        'motivationLowMultiplier'                => [0.75, 0.0, 3.0],
        'motivationPerformanceHighMultiplier'    => [1.15, 0.0, 2.0],
        'motivationPerformanceLowMultiplier'     => [0.85, 0.0, 2.0],
        'motivationPerformanceVeryLowMultiplier' => [0.7, 0.0, 2.0],
        'gametimePromiseKeptMotivationDelta'     => [8, 0, 100],
        'gametimePromiseKeptMoraleDelta'         => [10, 0, 100],
        'gametimePromiseBrokenMoraleDelta'       => [-20, -100, 0],
        'contractBreachMotivationDelta'          => [-2, -100, 0],
        'cliqueMotivationBleedShare'             => [0.3, 0.0, 1.0],
        'staffResultMoraleWinLow'                => [10, 0, 100],
        'staffResultMoraleWinMid'                => [5, 0, 100],
        'staffResultMoraleWinHigh'               => [2, 0, 100],
        'staffResultMoraleDrawLow'               => [5, 0, 100],
        'staffResultMoraleDrawMid'               => [3, 0, 100],
        'staffResultMoraleDrawHigh'              => [1, 0, 100],
        'staffResultMoraleLowBandMax'            => [50, 0, 100],
        'staffResultMoraleMidBandMax'            => [80, 0, 100],
        'managerMotivationBaseline'              => [60, 0, 100],
        'managerMotivationWeeklyDrift'           => [2, 0, 20],
    ];

    /** @return iterable<string, array{string, int|float, int|float, int|float}> */
    public static function dials(): iterable
    {
        foreach (self::DIALS as $key => [$default, $min, $max]) {
            yield $key => [$key, $default, $min, $max];
        }
    }

    #[DataProvider('dials')]
    public function testDefaultMirrorsTheClient(string $key, int|float $default): void
    {
        self::assertSame($default, (new GameConfig())->{'get' . ucfirst($key)}());
    }

    #[DataProvider('dials')]
    public function testSetterClampsToItsRange(string $key, int|float $default, int|float $min, int|float $max): void
    {
        $c = new GameConfig();
        $set = 'set' . ucfirst($key);
        $get = 'get' . ucfirst($key);

        self::assertSame($max, $c->$set($max + 50)->$get());
        self::assertSame($min, $c->$set($min - 50)->$get());
        self::assertSame($default, $c->$set($default)->$get());
    }

    #[DataProvider('dials')]
    public function testTheApiServesItUnderTheClientKey(string $key): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Controller/Api/GameConfigController.php');
        self::assertMatchesRegularExpression(
            '/\'' . $key . '\'\s*=>\s*\$config->get' . ucfirst($key) . '\(\)/',
            $source,
        );
    }

    #[DataProvider('dials')]
    public function testTheAdminSaveHandlerReadsIt(string $key): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Controller/Admin/DashboardController.php');
        self::assertStringContainsString("set" . ucfirst($key) . "(", $source);
        self::assertStringContainsString("request->get('" . $key . "'", $source);
    }
}
