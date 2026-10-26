<?php

declare(strict_types=1);

namespace App\Message\Pool;

/**
 * One bounded chunk of pool generation for one nationality, queued by
 * `app:starter-pool:audit` when a country's starter-pack check comes back short.
 *
 * A chunk, not a country: `app:pool:warm` generates a country's whole deficit in a
 * single ~20s request (500 players + 150 staff + 50 scouts cold), which is fine for an
 * admin who pressed a button and is watching, and wrong for an unattended cron across
 * 25 enabled countries. Splitting the deficit into `count`-sized messages means the
 * consumer's memory and transaction size are bounded by the chunk rather than by how
 * far behind the pool has fallen.
 *
 * `target` rides along with `count` so the handler can re-check before generating:
 * by the time a chunk is drained, earlier chunks (or an admin pressing Warm) may have
 * already met the floor, and generating anyway would overshoot it by a whole queue's
 * worth. See TopUpStarterPoolMessageHandler.
 */
final class TopUpStarterPoolMessage
{
    public const KIND_PLAYERS = 'players';
    public const KIND_STAFF   = 'staff';
    public const KIND_SCOUTS  = 'scouts';

    public function __construct(
        /** Country code the audit was run for — carried for logging only; generation keys off nationality. */
        public readonly string $country,
        public readonly string $nationality,
        /** One of the KIND_* constants. */
        public readonly string $kind,
        /** How many entities this chunk may generate, before the target re-check clamps it. */
        public readonly int $count,
        /** The pool floor this top-up is working towards, for that nationality (and role, for staff). */
        public readonly int $target,
        /** A StaffRole value when $kind is KIND_STAFF; null otherwise. */
        public readonly ?string $staffRole = null,
    ) {}
}
