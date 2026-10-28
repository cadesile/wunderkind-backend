<?php

declare(strict_types=1);

namespace App\MessageHandler\Competition;

use App\Entity\Competition\CompetitionRound;
use App\Message\Competition\DrawCompetitionRoundMessage;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Message\Competition\WarmCompetitionResponseMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Draws one round.
 *
 * **Takes the claim itself**, rather than trusting the dispatcher to have taken one. That is
 * what makes a duplicate delivery free: the dispatcher sends a message for every due round
 * on every tick without tracking what it already sent, and a second handler for the same
 * round simply finds the lease held (or the round no longer draw-pending) and returns.
 *
 * A missing round is not an error either. Rounds go away — an admin can delete a competition
 * while messages for it are still queued — and the message outliving its subject is an
 * ordinary consequence of decoupling, not a failure to retry.
 *
 * Exceptions from the draw itself are deliberately **not** swallowed, following
 * TopUpStarterPoolMessageHandler: letting them reach Messenger puts the message on the
 * `failed` transport where `messenger:failed:retry` can replay it, and replaying is safe
 * because the claim is a lease — a round whose draw died mid-flight is reclaimable once the
 * lease expires, and is still DRAW_PENDING because the status transition commits with the
 * fixtures rather than ahead of them.
 */
#[AsMessageHandler]
final class DrawCompetitionRoundMessageHandler
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionDrawService $drawService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(DrawCompetitionRoundMessage $message): void
    {
        $round = $this->roundRepository->find($message->roundId);

        if (!$round instanceof CompetitionRound) {
            $this->logger->info('[competition] draw message for a round that no longer exists', ['roundId' => $message->roundId]);

            return;
        }

        if (!$this->drawService->drawRoundIfClaimable($round, new \DateTimeImmutable())) {
            $this->logger->info('[competition] draw skipped — claimed elsewhere or no longer draw-pending', [
                'roundId'       => $message->roundId,
                'competitionId' => (string) $round->getActiveCompetition()->getId(),
                'status'        => $round->getStatus()->value,
            ]);

            return;
        }

        // The bracket response changed, so queue a rebuild. A separate message rather than a
        // call here: this handler has the round's entrants in its identity map, and building
        // the document from those while fingerprinting fresh SQL is exactly how a stale
        // bracket used to get stored under a fresh key. The worker clears the EntityManager
        // between messages, so the warm handler reads clean.
        $this->messageBus->dispatch(new WarmCompetitionResponseMessage(
            (string) $round->getActiveCompetition()->getId(),
        ));
    }
}
