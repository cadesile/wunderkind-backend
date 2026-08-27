<?php

namespace App\Service\Admin;

/**
 * Shared bucketing for the admin dashboard breakdowns.
 *
 * Every pool repository funnels its numeric/date columns through here so the
 * bucket labels are identical across Players, Staff, Scouts and Agents — the
 * dashboard renders them as a single generic facet list and the labels are the
 * only thing tying a chart axis to a table row.
 */
final class StatBuckets
{
    public const UNKNOWN = 'Unknown';

    /** Ordered label sets — used to seed a zero-filled bucket map so empty buckets still render. */
    public const AGE_LABELS        = ['U16', '16-18', '19-21', '22-25', '26-30', '30+'];
    public const ABILITY_LABELS    = ['1-20', '21-40', '41-60', '61-80', '81-100'];
    public const EXPERIENCE_LABELS = ['0-2', '3-5', '6-10', '11-20', '20+'];
    public const RATING_LABELS     = self::ABILITY_LABELS;
    public const COMMISSION_LABELS = ['0-5%', '5-10%', '10-15%', '15-20%', '20%+'];

    /**
     * Age band from a date of birth. Nullable because Staff/Scout/Agent all
     * carry a nullable `dob` — a null lands in its own bucket rather than
     * silently vanishing from the totals.
     */
    public static function age(?\DateTimeInterface $dob, ?\DateTimeImmutable $now = null): string
    {
        if (!$dob instanceof \DateTimeInterface) {
            return self::UNKNOWN;
        }

        $age = (int) $dob->diff($now ?? new \DateTimeImmutable())->y;

        return match (true) {
            $age < 16  => 'U16',
            $age <= 18 => '16-18',
            $age <= 21 => '19-21',
            $age <= 25 => '22-25',
            $age <= 30 => '26-30',
            default    => '30+',
        };
    }

    public static function ability(int $value): string
    {
        return match (true) {
            $value <= 20 => '1-20',
            $value <= 40 => '21-40',
            $value <= 60 => '41-60',
            $value <= 80 => '61-80',
            default      => '81-100',
        };
    }

    public static function rating(int $value): string
    {
        return self::ability($value);
    }

    public static function experience(int $years): string
    {
        return match (true) {
            $years <= 2  => '0-2',
            $years <= 5  => '3-5',
            $years <= 10 => '6-10',
            $years <= 20 => '11-20',
            default      => '20+',
        };
    }

    /** `commissionRate` is a Doctrine `decimal`, i.e. a PHP string — cast before comparing. */
    public static function commission(string|float|int|null $rate): string
    {
        $value = (float) ($rate ?? 0);

        return match (true) {
            $value < 5  => '0-5%',
            $value < 10 => '5-10%',
            $value < 15 => '10-15%',
            $value < 20 => '15-20%',
            default     => '20%+',
        };
    }

    /**
     * Zero-filled map in label order, so a bucket with no rows still appears
     * (an absent bar is information too).
     *
     * @param  list<string>      $labels
     * @return array<string,int>
     */
    public static function seed(array $labels): array
    {
        return array_fill_keys($labels, 0);
    }

    /**
     * Convert a `label => count` map into the dashboard's facet shape.
     *
     * @param  array<string,int>                    $counts
     * @return list<array{key: string, count: int}>
     */
    public static function facet(array $counts, bool $sortByCount = false, ?int $limit = null): array
    {
        if ($sortByCount) {
            arsort($counts);
        }

        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = ['key' => (string) $key, 'count' => (int) $count];
        }

        return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * Build the dashboard's nested drill-down shape: one row per parent key,
     * each carrying a facet list for every child dimension.
     *
     * @param  array<string,int>                               $parentCounts already sorted desc
     * @param  array<string, array<string, array<string,int>>> $childCounts  parentKey => dimension => label => count
     * @param  list<string>                                    $children
     * @return array{dimension: string, children: list<string>, rows: list<array{key: string, count: int, children: array<string, list<array{key: string, count: int}>>}>}
     */
    public static function nested(string $dimension, array $parentCounts, array $childCounts, array $children): array
    {
        $rows = [];
        foreach ($parentCounts as $key => $count) {
            $kids = [];
            foreach ($children as $dim) {
                $kids[$dim] = self::facet($childCounts[$key][$dim] ?? [], true);
            }
            $rows[] = ['key' => (string) $key, 'count' => (int) $count, 'children' => $kids];
        }

        return ['dimension' => $dimension, 'children' => $children, 'rows' => $rows];
    }
}
