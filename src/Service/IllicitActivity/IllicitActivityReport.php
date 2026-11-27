<?php

declare(strict_types=1);

namespace App\Service\IllicitActivity;

use App\Enum\IllicitActivityKind;

/** One validated `illicitActivity` record from a sync payload. Built only by IllicitActivityValidator. */
final class IllicitActivityReport
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $clientId,
        public readonly IllicitActivityKind $kind,
        public readonly int $startWeek,
        public readonly int $startSeason,
        public readonly int $suspicion,
        public readonly string $status,
        public readonly ?int $endedWeek,
        public readonly array $details,
    ) {}
}
