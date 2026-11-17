<?php

declare(strict_types=1);

namespace App\Message\WorldPack;

/**
 * "Country $country may be short of its world pack set buffer — restock it."
 *
 * Carries only the country code, deliberately. The handler re-reads the deficit, so a
 * duplicate dispatch, a redelivery and a message queued behind one that already did the work
 * are all no-ops. Nothing here needs to be true by the time it drains.
 *
 * Dispatched by InitializeController on every game initialisation and by
 * `app:worldpack:maintain`. Handled by TopUpWorldPackSetMessageHandler on the `worldpackset`
 * transport.
 */
final class TopUpWorldPackSetMessage
{
    public function __construct(
        private readonly string $country,
        private readonly int $max = 1,
    ) {}

    public function getCountry(): string { return $this->country; }
    public function getMax(): int { return $this->max; }
}
