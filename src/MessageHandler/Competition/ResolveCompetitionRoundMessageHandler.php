<?php

declare(strict_types=1);

namespace App\MessageHandler\Competition;

use App\Entity\Competition\CompetitionRound;
use App\Message\Competition\ResolveCompetitionRoundMessage;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionResultsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Publishes one round's results.
 *
 * Same contract as DrawCompetitionRoundMessageHandler — claim taken here, duplicate delivery
 * free, missing round not an error, exceptions allowed to reach the `failed` transport.
 *
 * Replaying a failed resolve is safe on two counts beyond the lease: publishResults() skips
 * any fixture that already carries `processedAt`, and a round's work lands in a single flush.
 * So a retried round either continues from where the last attempt stopped or redoes work that
 * was never committed — it cannot double-resolve a fixture.
 */
#[AsMessageHandler]
final class ResolveCompetitionRoundMessageHandler
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionResultsService $resultsService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ResolveCompetitionRoundMessage $message): void
    {
        $round = $this->roundRepository->find($message->roundId);

        if (!$round instanceof CompetitionRound) {
            $this->logger->info('[competition] resolve message for a round that no longer exists', ['roundId' => $message->roundId]);

            return;
        }

        if (!$this->resultsService->publishResultsIfClaimable($round, new \DateTimeImmutable())) {
            $this->logger->info('[competition] resolve skipped — claimed elsewhere or no longer drawn', [
                'roundId'       => $message->roundId,
                'competitionId' => (string) $round->getActiveCompetition()->getId(),
                'status'        => $round->getStatus()->value,
            ]);
        }
    }
}
