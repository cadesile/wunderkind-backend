<?php

declare(strict_types=1);

namespace App\MessageHandler\Location;

use App\Message\Location\EnrichLocationMessage;
use App\Repository\Location\LocationEnrichmentRunRepository;
use App\Service\Location\Enrichment\LocationEnrichmentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Enriches one location.
 *
 * Re-reads the row and refreshes its lease itself rather than trusting the dispatcher, so a
 * duplicate delivery is free and a message that outlives its location is a no-op. A missing or
 * already-settled location is not an error — an admin can delete or reset a location while
 * messages for it are still queued, and that is an ordinary consequence of decoupling rather
 * than a failure to retry.
 *
 * EXCEPTIONS ARE NOT SWALLOWED, following TopUpStarterPoolMessageHandler. Letting them reach
 * Messenger puts the message on the `failed` transport where `messenger:failed:retry` can
 * replay it, and replaying is safe: the location's status is written before the exception
 * escapes, and the attempt row recording the failure is already committed. Retrying a
 * transport error is exactly the case the `failed` transport exists for.
 *
 * The one thing it must do before rethrowing is make sure the failure left a trace —
 * LocationEnrichmentService::enrich() writes the attempt row first, for the reason its own
 * docblock gives.
 */
#[AsMessageHandler]
final class EnrichLocationMessageHandler
{
    public function __construct(
        private readonly LocationEnrichmentService       $enrichmentService,
        private readonly LocationEnrichmentRunRepository $runRepository,
        private readonly LoggerInterface                 $logger,
    ) {}

    public function __invoke(EnrichLocationMessage $message): void
    {
        $location = $this->enrichmentService->beginWork($message->locationId);

        if ($location === null) {
            $this->logger->debug('Enrichment message skipped — location gone or already settled', [
                'location' => $message->locationId,
            ]);

            return;
        }

        $run = $message->runId === null
            ? null
            : $this->runRepository->find($message->runId);

        $this->enrichmentService->enrich($location, $run);
    }
}
