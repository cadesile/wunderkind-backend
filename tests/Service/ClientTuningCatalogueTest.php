<?php

namespace App\Tests\Service;

use App\Entity\GameConfig;
use App\Service\ClientTuningCatalogue;
use PHPUnit\Framework\TestCase;

final class ClientTuningCatalogueTest extends TestCase
{
    public function testEveryKeyHasAKnownGroupTypeAndAFittingDefault(): void
    {
        foreach (ClientTuningCatalogue::KEYS as $key => $def) {
            self::assertArrayHasKey($def['group'], ClientTuningCatalogue::GROUPS, $key);
            self::assertContains($def['type'], ['int', 'float', 'bool', 'json'], $key);
            self::assertTrue(ClientTuningCatalogue::fits($def['type'], $def['default']), "$key default does not fit its type");
            self::assertNotSame('', $def['label'], $key);
        }
    }

    public function testNoCatalogueKeyIsAlsoAColumn(): void
    {
        $props = array_map(fn (\ReflectionProperty $p) => $p->getName(), (new \ReflectionClass(GameConfig::class))->getProperties());
        self::assertSame([], array_values(array_intersect(array_keys(ClientTuningCatalogue::KEYS), $props)));
    }

    public function testAnEmptyRowResolvesToTheDefaults(): void
    {
        self::assertSame(ClientTuningCatalogue::defaults(), (new GameConfig())->getResolvedClientTuning());
    }

    public function testOverridesApplyAndBadOnesAreDropped(): void
    {
        $c = (new GameConfig())->setClientTuning([
            'youthMinAge'              => 13,
            'youthMaxAge'              => 'sixteen',  // wrong type
            'notAKey'                  => 1,
            'dofRequireManagerConsent' => true,
        ]);
        self::assertSame(['youthMinAge' => 13, 'dofRequireManagerConsent' => true], $c->getClientTuning());
        $r = $c->getResolvedClientTuning();
        self::assertSame(13, $r['youthMinAge']);
        self::assertSame(15, $r['youthMaxAge']);
        self::assertArrayNotHasKey('notAKey', $r);
    }

    public function testParse(): void
    {
        self::assertSame([true, 12], ClientTuningCatalogue::parse('youthMinAge', '12'));
        self::assertFalse(ClientTuningCatalogue::parse('youthMinAge', '12.5')[0]);
        self::assertSame([true, 5.5], ClientTuningCatalogue::parse('pyramidTaxPercent', '5.5'));
        self::assertSame([true, 5], ClientTuningCatalogue::parse('pyramidTaxPercent', '5'));
        self::assertSame([true, false], ClientTuningCatalogue::parse('dofRequireManagerConsent', '0'));
        self::assertSame([true, [1, 2]], ClientTuningCatalogue::parse('youthIntakeByFacilityLevel', '[1,2]'));
        self::assertFalse(ClientTuningCatalogue::parse('youthIntakeByFacilityLevel', 'nope')[0]);
        self::assertFalse(ClientTuningCatalogue::parse('notAKey', '1')[0]);
    }

    public function testStartingBalancesEncodeAsATierObject(): void
    {
        $json = json_encode(ClientTuningCatalogue::defaults()['npcStartingBalanceByTier']);
        self::assertStringStartsWith('{"1":', $json);
    }

    public function testInvestorPremiumRangesAreServedAndOrdered(): void
    {
        $d = ClientTuningCatalogue::defaults();
        foreach (['Small', 'Medium', 'Large'] as $size) {
            self::assertIsFloat($d["investorPremium{$size}Min"]);
            self::assertLessThanOrEqual($d["investorPremium{$size}Max"], $d["investorPremium{$size}Min"]);
        }
        self::assertSame(1.0, $d['investorPremiumSmallMin']);
        self::assertSame(2.0, $d['investorPremiumLargeMax']);
    }
}
