<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Service\Admin\StatBuckets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bucket boundaries are the contract between a chart axis and a table row —
 * if a label drifts, the two views silently stop agreeing. Pin every edge.
 */
class StatBucketsTest extends TestCase
{
    private const NOW = '2026-08-27';

    private function dobForAge(int $age): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::NOW))->modify("-{$age} years");
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * @return list<array{int, string}>
     */
    public static function ageProvider(): array
    {
        return [
            [0, 'U16'], [15, 'U16'],
            [16, '16-18'], [18, '16-18'],
            [19, '19-21'], [21, '19-21'],
            [22, '22-25'], [25, '22-25'],
            [26, '26-30'],
            // 30 belongs in the band its label names. The original inline
            // implementation used `< 30` here and pushed exactly-30 into '30+'.
            [30, '26-30'],
            [31, '30+'], [45, '30+'],
        ];
    }

    #[DataProvider('ageProvider')]
    public function testAgeBands(int $age, string $expected): void
    {
        self::assertSame($expected, StatBuckets::age($this->dobForAge($age), $this->now()));
    }

    public function testNullDobIsItsOwnBucketRatherThanVanishing(): void
    {
        self::assertSame('Unknown', StatBuckets::age(null));
        self::assertSame('Unknown', StatBuckets::UNKNOWN);
    }

    /** @return list<array{int, string}> */
    public static function abilityProvider(): array
    {
        return [
            [0, '1-20'], [1, '1-20'], [20, '1-20'],
            [21, '21-40'], [40, '21-40'],
            [41, '41-60'], [60, '41-60'],
            [61, '61-80'], [80, '61-80'],
            [81, '81-100'], [100, '81-100'], [200, '81-100'],
        ];
    }

    #[DataProvider('abilityProvider')]
    public function testAbilityBands(int $value, string $expected): void
    {
        self::assertSame($expected, StatBuckets::ability($value));
        self::assertSame($expected, StatBuckets::rating($value), 'rating() must stay an alias of ability()');
    }

    /** @return list<array{int, string}> */
    public static function experienceProvider(): array
    {
        return [
            [0, '0-2'], [2, '0-2'],
            [3, '3-5'], [5, '3-5'],
            [6, '6-10'], [10, '6-10'],
            [11, '11-20'], [20, '11-20'],
            [21, '20+'],
        ];
    }

    #[DataProvider('experienceProvider')]
    public function testExperienceBands(int $years, string $expected): void
    {
        self::assertSame($expected, StatBuckets::experience($years));
    }

    /** commissionRate is a Doctrine `decimal` — it arrives as a string. */
    public function testCommissionAcceptsDecimalStrings(): void
    {
        self::assertSame('0-5%', StatBuckets::commission('0.00'));
        self::assertSame('0-5%', StatBuckets::commission('4.99'));
        self::assertSame('5-10%', StatBuckets::commission('5.00'));
        self::assertSame('10-15%', StatBuckets::commission('10.00'));
        self::assertSame('15-20%', StatBuckets::commission('19.99'));
        self::assertSame('20%+', StatBuckets::commission('20.00'));
        self::assertSame('0-5%', StatBuckets::commission(null));
    }

    public function testSeedProducesZeroFilledMapInLabelOrder(): void
    {
        $seeded = StatBuckets::seed(StatBuckets::ABILITY_LABELS);

        self::assertSame(StatBuckets::ABILITY_LABELS, array_keys($seeded));
        self::assertSame([0, 0, 0, 0, 0], array_values($seeded));
    }

    public function testFacetPreservesInsertionOrderUnlessSorted(): void
    {
        $counts = ['b' => 1, 'a' => 9, 'c' => 5];

        self::assertSame(
            [['key' => 'b', 'count' => 1], ['key' => 'a', 'count' => 9], ['key' => 'c', 'count' => 5]],
            StatBuckets::facet($counts)
        );

        self::assertSame(
            [['key' => 'a', 'count' => 9], ['key' => 'c', 'count' => 5]],
            StatBuckets::facet($counts, sortByCount: true, limit: 2)
        );
    }

    public function testNestedBuildsOneRowPerParentWithEveryChildDimension(): void
    {
        $parents = ['English' => 3, 'Spanish' => 1];
        $children = [
            'English' => ['position' => ['MID' => 2, 'GK' => 1], 'age' => ['U16' => 3]],
            'Spanish' => ['position' => ['DEF' => 1]],
        ];

        $nested = StatBuckets::nested('nationality', $parents, $children, ['position', 'age']);

        self::assertSame('nationality', $nested['dimension']);
        self::assertSame(['position', 'age'], $nested['children']);
        self::assertCount(2, $nested['rows']);

        self::assertSame('English', $nested['rows'][0]['key']);
        self::assertSame(3, $nested['rows'][0]['count']);
        self::assertSame(
            [['key' => 'MID', 'count' => 2], ['key' => 'GK', 'count' => 1]],
            $nested['rows'][0]['children']['position'],
            'child facets are sorted by count'
        );

        // A parent missing a dimension gets an empty list, never a missing key —
        // the renderer iterates `children` blindly.
        self::assertSame([], $nested['rows'][1]['children']['age']);
    }
}
