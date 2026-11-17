<?php

declare(strict_types=1);

namespace App\MessageHandler\WorldPack;

use App\Message\WorldPack\TopUpWorldPackSetMessage;
use App\Service\WorldPack\WorldPackSetTopUpService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Restocks one country's world pack set buffer.
 *
 * Thin on purpose: every guard that matters (the deficit re-read, the per-country advisory
 * lock, the per-set failure handling) lives in WorldPackSetTopUpService, because
 * `app:worldpack:topup` and `app:worldpack:maintain` need exactly the same guards and a
 * handler is the one place a command cannot reuse.
 *
 * Exceptions are allowed to escape to the `failed` transport, like
 * TopUpStarterPoolMessageHandler's: no admin progress row is watching this, and the service's
 * deficit re-read makes a replay harmless. Swallowing them here would hide a country that
 * cannot restock.
 */
#[AsMessageHandler]
final class TopUpWorldPackSetMessageHandler
{
    public function __construct(private readonly WorldPackSetTopUpService $topUpService) {}

    public function __invoke(TopUpWorldPackSetMessage $message): void
    {
        $this->topUpService->topUp($message->getCountry(), $message->getMax());
    }
}
