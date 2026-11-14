<?php

declare(strict_types=1);

namespace App\MessageHandler\Club;

use App\Entity\Club\ClubSale;
use App\Message\Club\SellClubMessage;
use App\Repository\Club\ClubSaleRepository;
use App\Service\Club\ClubSaleService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one authorised club buyout off the queue.
 *
 * A NUDGE, NOT A WORK ORDER. The message carries an id; this re-reads the row and lets
 * ClubSaleService decide what is still outstanding. So a duplicate delivery, a retry after a
 * crash, and a batch that enqueued the same sale twice all converge on the same single payout.
 *
 * EXCEPTIONS ARE NOT CAUGHT, deliberately — the house policy for destructive and financial work.
 * A failure belongs in the `failed` transport where `messenger:failed:show` surfaces it, not
 * swallowed into a log line behind a PENDING row nobody is watching. The sale stays PENDING and
 * is safe to retry once the cause is fixed.
 *
 * The two quiet returns below are both legitimate, not failures: a sale deleted by an operator
 * between dispatch and consumption, and a sale some earlier delivery already completed.
 */
#[AsMessageHandler]
final class SellClubMessageHandler
{
    public function __construct(
        private readonly ClubSaleRepository $saleRepository,
        private readonly ClubSaleService    $saleService,
        private readonly LoggerInterface    $logger,
    ) {}

    public function __invoke(SellClubMessage $message): void
    {
        $sale = $this->saleRepository->find($message->saleId);

        if (!$sale instanceof ClubSale) {
            $this->logger->info('[club-sale] message for a sale that no longer exists', [
                'saleId' => $message->saleId,
            ]);

            return;
        }

        if ($sale->isCompleted()) {
            $this->logger->info('[club-sale] already completed; skipping', [
                'saleId' => $message->saleId,
            ]);

            return;
        }

        $this->saleService->execute($sale);
    }
}
