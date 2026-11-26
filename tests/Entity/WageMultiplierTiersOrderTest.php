<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

/**
 * The admin form shows the wage ladder highest first, but every reader walks it first-match on
 * `ability < maxAbility`. Saved in display order, everyone below the top bound would be priced
 * at the TOP multiplier, so the entity puts the rows back in ascending order, catch-all last.
 */
final class WageMultiplierTiersOrderTest extends TestCase
{
    public function testHighestFirstIsStoredAscendingWithCatchAllLast(): void
    {
        $config = (new GameConfig())->setWageMultiplierTiers([
            ['maxAbility' => null, 'playerMultiplier' => 28.0, 'staffMultiplier' => 12.0],
            ['maxAbility' => 40,   'playerMultiplier' => 14.0, 'staffMultiplier' => 8.0],
            ['maxAbility' => 20,   'playerMultiplier' => 7.0,  'staffMultiplier' => 4.0],
            ['maxAbility' => 10,   'playerMultiplier' => 5.0,  'staffMultiplier' => 2.0],
        ]);

        self::assertSame([10, 20, 40, null], array_column($config->getWageMultiplierTiers(), 'maxAbility'));
        self::assertSame([5.0, 7.0, 14.0, 28.0], array_column($config->getWageMultiplierTiers(), 'playerMultiplier'));
    }

    public function testFromAbilityRowsBecomeCeilingsWithTopCatchAll(): void
    {
        $tiers = GameConfig::wageTiersFromMinimums([
            ['fromAbility' => 60, 'playerMultiplier' => 28, 'staffMultiplier' => 12],
            ['fromAbility' => 40, 'playerMultiplier' => 14, 'staffMultiplier' => 8],
            ['fromAbility' => 20, 'playerMultiplier' => 7,  'staffMultiplier' => 4],
            ['fromAbility' => 0,  'playerMultiplier' => 5,  'staffMultiplier' => 2],
        ]);

        self::assertSame([20, 40, 60, null], array_column($tiers, 'maxAbility'));
        self::assertSame([5.0, 7.0, 14.0, 28.0], array_column($tiers, 'playerMultiplier'));
    }

    public function testAlreadyAscendingTableIsUnchanged(): void
    {
        $tiers = (new GameConfig())->getWageMultiplierTiers();

        self::assertSame($tiers, GameConfig::sortWageMultiplierTiers($tiers));
        self::assertSame($tiers, GameConfig::sortWageMultiplierTiers(array_reverse($tiers)));
    }
}
