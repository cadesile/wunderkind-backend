<?php

declare(strict_types=1);

namespace App\Service\IllicitActivity;

use App\Entity\IllicitActivity;
use App\Enum\CompanySize;
use App\Enum\IllicitActivityKind;

/**
 * Checks a sync payload's `illicitActivity` list, record by record (2026-11-27).
 *
 * A BAD RECORD NEVER FAILS A SYNC. Each invalid record is dropped on its own with a reason, and
 * the rest of the sync goes through unchanged. Only the fields below are kept; anything else in
 * a record is ignored.
 *
 * Shape (money in REAL pence, not the ledger's ×100 quirk):
 *   { id, kind, startWeek, startSeason, suspicion 0–100, status active|ended, endedWeek|null,
 *     details: kind-specific }
 *
 * `fake_sponsor` details: name (1–80 chars), weeklyPayment, contractWeeks, endWeek (positive
 * ints), companySize (small|medium|large, any case; stored lowercase), trigger (bleed|table).
 */
final class IllicitActivityValidator
{
    public const MAX_RECORDS = 200;

    private const TRIGGERS = ['bleed', 'table'];

    /**
     * @param mixed $raw the payload's `illicitActivity` value
     *
     * @return array{records: list<IllicitActivityReport>, dropped: list<array{index: int, reason: string}>, trimmed: int}
     */
    public function validate(mixed $raw): array
    {
        $records = [];
        $dropped = [];
        $trimmed = 0;
        if (!\is_array($raw)) {
            return ['records' => [], 'dropped' => $raw === null ? [] : [['index' => -1, 'reason' => 'not_a_list']], 'trimmed' => 0];
        }

        $list = array_values($raw);
        if (\count($list) > self::MAX_RECORDS) {
            $trimmed = \count($list) - self::MAX_RECORDS;
            $list    = \array_slice($list, 0, self::MAX_RECORDS);
        }

        $seen = [];
        foreach ($list as $i => $item) {
            $reason = null;
            $report = \is_array($item) ? $this->one($item, $reason) : null;
            if ($report === null) {
                $dropped[] = ['index' => $i, 'reason' => $reason ?? 'not_an_object'];
                continue;
            }
            if (isset($seen[$report->clientId])) {
                $dropped[] = ['index' => $i, 'reason' => 'duplicate_id'];
                continue;
            }
            $seen[$report->clientId] = true;
            $records[] = $report;
        }

        return ['records' => $records, 'dropped' => $dropped, 'trimmed' => $trimmed];
    }

    /** @param array<mixed> $r */
    private function one(array $r, ?string &$reason): ?IllicitActivityReport
    {
        $id = $r['id'] ?? null;
        if (!\is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            $reason = 'invalid_id';
            return null;
        }

        $kind = \is_string($r['kind'] ?? null) ? IllicitActivityKind::tryFrom($r['kind']) : null;
        if ($kind === null) {
            $reason = 'unknown_kind';
            return null;
        }

        $startWeek   = $r['startWeek'] ?? null;
        $startSeason = $r['startSeason'] ?? null;
        if (!self::isPositiveInt($startWeek) || !self::isPositiveInt($startSeason)) {
            $reason = 'invalid_start';
            return null;
        }

        $suspicion = $r['suspicion'] ?? null;
        if (!\is_int($suspicion) || $suspicion < 0 || $suspicion > 100) {
            $reason = 'invalid_suspicion';
            return null;
        }

        $status = $r['status'] ?? null;
        if (!\is_string($status) || !\in_array($status, IllicitActivity::STATUSES, true)) {
            $reason = 'invalid_status';
            return null;
        }

        $endedWeek = $r['endedWeek'] ?? null;
        if ($status === IllicitActivity::STATUS_ENDED ? !self::isPositiveInt($endedWeek) : $endedWeek !== null) {
            $reason = 'invalid_ended_week';
            return null;
        }

        $details = \is_array($r['details'] ?? null) ? $r['details'] : null;
        $clean   = $details === null ? null : match ($kind) {
            IllicitActivityKind::FAKE_SPONSOR => self::fakeSponsorDetails($details),
        };
        if ($clean === null) {
            $reason = 'invalid_details';
            return null;
        }

        return new IllicitActivityReport($id, $kind, $startWeek, $startSeason, $suspicion, $status, $endedWeek, $clean);
    }

    /**
     * @param array<mixed> $d
     *
     * @return array<string, mixed>|null
     */
    private static function fakeSponsorDetails(array $d): ?array
    {
        $name = \is_string($d['name'] ?? null) ? trim($d['name']) : '';
        if ($name === '' || mb_strlen($name) > 80) {
            return null;
        }
        foreach (['weeklyPayment', 'contractWeeks', 'endWeek'] as $k) {
            if (!self::isPositiveInt($d[$k] ?? null)) {
                return null;
            }
        }
        $size = \is_string($d['companySize'] ?? null) ? CompanySize::tryFrom(strtolower($d['companySize'])) : null;
        if ($size === null) {
            return null;
        }
        $trigger = $d['trigger'] ?? null;
        if (!\is_string($trigger) || !\in_array($trigger, self::TRIGGERS, true)) {
            return null;
        }

        return [
            'name'          => $name,
            'weeklyPayment' => $d['weeklyPayment'],
            'contractWeeks' => $d['contractWeeks'],
            'endWeek'       => $d['endWeek'],
            'companySize'   => $size->value,
            'trigger'       => $trigger,
        ];
    }

    private static function isPositiveInt(mixed $v): bool
    {
        return \is_int($v) && $v > 0;
    }
}
