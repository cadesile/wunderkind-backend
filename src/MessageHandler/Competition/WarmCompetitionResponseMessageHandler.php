<?php

declare(strict_types=1);

namespace App\MessageHandler\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Message\Competition\WarmCompetitionResponseMessage;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Service\Competition\CompetitionResponseBuilder;
use App\Service\Competition\CompetitionResponseCacheService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Rebuilds one competition's prebuilt bracket response, off the cron tick.
 *
 * Safe to call rebuild() from here — and specifically *not* safe from a cron tick — because
 * the worker clears the EntityManager before each message, so nothing this handler did not
 * load itself is in the identity map. That is the precondition rebuild()'s own docblock
 * states, and the reason prewarming is a message rather than a tail call on the draw and
 * resolve handlers.
 *
 * Failures are swallowed. A warm is pure optimisation: the cache validates itself against the
 * competition's fingerprint, so a missing or superseded document is a miss and the next
 * reader builds it. Letting a warm failure reach the `failed` transport would put a retryable
 * row in front of an operator for something that self-heals on the next read, and — worse —
 * a retry storm on a genuinely broken competition would keep rebuilding a 64-entrant document
 * for nobody.
 */
#[AsMessageHandler]
final class WarmCompetitionResponseMessageHandler
{
    public function __construct(
        private readonly ActiveCompetitionRepository $activeCompetitionRepository,
        private readonly CompetitionResponseBuilder $responseBuilder,
        private readonly CompetitionResponseCacheService $responseCache,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(WarmCompetitionResponseMessage $message): void
    {
        $competition = $this->activeCompetitionRepository->find($message->competitionId);

        if (!$competition instanceof ActiveCompetition) {
            $this->logger->info('[competition] warm message for a competition that no longer exists', [
                'competitionId' => $message->competitionId,
            ]);

            return;
        }

        try {
            $this->responseCache->rebuild(
                $competition,
                $this->responseBuilder->stateFingerprint($competition),
                fn (): array => $this->responseBuilder->sharedDocument($competition),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[competition] response warm failed; the next reader will rebuild it', [
                'competitionId' => $message->competitionId,
                'exception'     => $e->getMessage(),
            ]);
        }
    }
}
