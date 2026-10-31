<?php

declare(strict_types=1);

namespace App\Service\Leaderboard;

use App\Enum\Leaderboard\AwardCadence;

/**
 * The one place a leaderboard period key is produced, parsed, labelled or walked.
 *
 * All-static and dependency-free on purpose — the TrophyConfigFactory /
 * CountryContentRegistry precedent — because entities, commands and services all need
 * it and an entity cannot be injected.
 *
 * Key formats, all comfortably inside leaderboard_entry.period's varchar(20):
 *
 *   | cadence   | format           | example  | len |
 *   |-----------|------------------|----------|-----|
 *   | weekly    | o-\WW            | 2026-W44 |  8  |
 *   | monthly   | Y-\Mm            | 2026-M10 |  8  |
 *   | quarterly | Y-\Q + quarter   | 2026-Q4  |  7  |
 *
 * The W / M / Q discriminator is what makes a key parseable back to its cadence without
 * being told, which is what cadenceOf() needs.
 *
 * Zero-padding is load-bearing: within one cadence these keys sort lexicographically
 * (2026-M09 < 2026-M10, 2026-W09 < 2026-W44), and the close job's monotonic claim guard
 * (last_closed_period_key < :dueKey) depends on it. LeaderboardPeriodTest pins it.
 *
 * The weekly key is byte-identical to the ISO-week key the game has always written, so a
 * weekly definition awards on the board that already exists — one less period to populate.
 */
final class LeaderboardPeriod
{
    /** The lifetime board. Not a cadence, has no bounds, and is never growth-scored. */
    public const ALL_TIME = 'all-time';

    private function __construct() {}

    /**
     * THE one implementation of the ISO-week key.
     *
     * Byte-identical to the raw (new \DateTimeImmutable())->format('o-\WW') this replaced
     * at four call sites (SyncService, GenerateLeaderboardsCommand x2,
     * DecayReputationCommand) — same format string, same default timezone, just in one
     * place. LeaderboardPeriodTest pins the equivalence against the raw expression so the
     * de-duplication is provably non-regressive.
     */
    public static function currentWeekKey(?\DateTimeImmutable $at = null): string
    {
        return ($at ?? new \DateTimeImmutable())->format('o-\WW');
    }

    public static function keyFor(AwardCadence $cadence, \DateTimeImmutable $at): string
    {
        return match ($cadence) {
            AwardCadence::WEEKLY    => self::currentWeekKey($at),
            AwardCadence::MONTHLY   => $at->format('Y-\Mm'),
            AwardCadence::QUARTERLY => $at->format('Y') . '-Q' . self::quarterOf((int) $at->format('n')),
        };
    }

    /**
     * The cadence a key belongs to, or null for 'all-time' and for anything that is not a
     * well-formed, in-range key. A key that formats correctly but names a period that does
     * not exist (2026-W53 in a 52-week ISO year, say) is rejected too, because boundsOf()
     * round-trips it.
     */
    public static function cadenceOf(string $key): ?AwardCadence
    {
        $cadence = self::shapeOf($key);

        if ($cadence === null) {
            return null;
        }

        // Round-trip: a structurally valid key whose derived start does not re-produce it
        // names no real period. setISODate() silently rolls W53 into the next ISO year.
        $bounds = self::deriveBounds($cadence, $key);

        if ($bounds === null || self::keyFor($cadence, $bounds[0]) !== $key) {
            return null;
        }

        return $cadence;
    }

    /**
     * [start inclusive, end EXCLUSIVE], both UTC, or null for 'all-time' and invalid keys.
     *
     * The first thing in the repo that parses a period string back to dates. It is what
     * lets the archive freeze period_starts_at / period_ends_at so no later reader ever
     * re-derives them from a key.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    public static function boundsOf(string $key): ?array
    {
        $cadence = self::cadenceOf($key);

        return $cadence === null ? null : self::deriveBounds($cadence, $key);
    }

    public static function nextKey(string $key): ?string
    {
        $cadence = self::cadenceOf($key);
        $bounds  = $cadence === null ? null : self::deriveBounds($cadence, $key);

        return $bounds === null ? null : self::keyFor($cadence, $bounds[1]);
    }

    public static function previousKey(string $key): ?string
    {
        $cadence = self::cadenceOf($key);
        $bounds  = $cadence === null ? null : self::deriveBounds($cadence, $key);

        // One day before the start lands inside the previous period for every cadence:
        // Sunday of the previous ISO week, the last day of the previous month, the last
        // day of the previous quarter.
        return $bounds === null ? null : self::keyFor($cadence, $bounds[0]->modify('-1 day'));
    }

    /**
     * 'all-time' plus any key naming a real period. Used by LeaderboardController to
     * short-circuit an unrecognised ?period= before it can mint a cache key and trigger a
     * recalculate() write.
     */
    public static function isValidKey(string $key): bool
    {
        return $key === self::ALL_TIME || self::cadenceOf($key) !== null;
    }

    /** Human label: "Week 44, 2026" / "October 2026" / "Q4 2026" / "All time". */
    public static function label(string $key): string
    {
        if ($key === self::ALL_TIME) {
            return 'All time';
        }

        $cadence = self::cadenceOf($key);

        if ($cadence === null) {
            return $key;
        }

        $bounds = self::deriveBounds($cadence, $key);

        return match ($cadence) {
            AwardCadence::WEEKLY    => sprintf('Week %d, %s', (int) substr($key, 6, 2), substr($key, 0, 4)),
            AwardCadence::MONTHLY   => $bounds[0]->format('F Y'),
            AwardCadence::QUARTERLY => substr($key, 5) . ' ' . substr($key, 0, 4),
        };
    }

    public static function quarterOf(int $month): int
    {
        return (int) ceil($month / 3);
    }

    /** Structural shape only — no range or existence check. */
    private static function shapeOf(string $key): ?AwardCadence
    {
        return match (true) {
            (bool) preg_match('/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/', $key) => AwardCadence::WEEKLY,
            (bool) preg_match('/^\d{4}-M(0[1-9]|1[0-2])$/', $key)         => AwardCadence::MONTHLY,
            (bool) preg_match('/^\d{4}-Q[1-4]$/', $key)                   => AwardCadence::QUARTERLY,
            default                                                        => null,
        };
    }

    /**
     * Bounds from an already shape-checked key, with no round-trip validation — so
     * cadenceOf() can use it to *perform* that validation without recursing.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private static function deriveBounds(AwardCadence $cadence, string $key): ?array
    {
        $utc  = new \DateTimeZone('UTC');
        $year = (int) substr($key, 0, 4);

        if ($cadence === AwardCadence::WEEKLY) {
            $start = (new \DateTimeImmutable('now', $utc))
                ->setISODate($year, (int) substr($key, 6, 2))
                ->setTime(0, 0);

            return [$start, $start->modify('+7 days')];
        }

        $month = $cadence === AwardCadence::MONTHLY
            ? (int) substr($key, 6, 2)
            : ((int) substr($key, 6, 1) - 1) * 3 + 1;

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month), $utc);

        if ($start === false) {
            return null;
        }

        return [$start, $start->modify($cadence === AwardCadence::MONTHLY ? '+1 month' : '+3 months')];
    }
}
