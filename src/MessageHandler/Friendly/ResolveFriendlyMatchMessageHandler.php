<?php

declare(strict_types=1);

namespace App\MessageHandler\Friendly;

use App\Entity\Friendly\FriendlyChallenge;
use App\Message\Friendly\ResolveFriendlyMatchMessage;
use App\Repository\Friendly\FriendlyChallengeRepository;
use App\Service\Friendly\FriendlyChallengeService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resolves one friendly match.
 *
 * Same contract as ResolveCompetitionRoundMessageHandler: the claim is taken here rather
 * than at dispatch, a duplicate delivery is free, a challenge that no longer exists is not
 * an error, and exceptions are allowed to reach the `failed` transport rather than being
 * swallowed — a friendly that cannot be resolved is two escrowed stakes going nowhere, so
 * it should be visible in `messenger:failed:show`, not logged and forgotten.
 *
 * Replaying a failed resolve is safe: everything resolveSingleMatch() commits — the audit
 * row, the result, the COMPLETE status and both inbox notes — lands in a single flush, so a
 * retry either redoes work that was never committed or finds a challenge that is no longer
 * ACCEPTED and declines the claim. See FriendlyChallengeService::claimResolution().
 */
#[AsMessageHandler]
final class ResolveFriendlyMatchMessageHandler
{
    public function __construct(
        private readonly FriendlyChallengeRepository $repository,
        private readonly FriendlyChallengeService $friendlyService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ResolveFriendlyMatchMessage $message): void
    {
        $challenge = $this->repository->find($message->challengeId);

        if (!$challenge instanceof FriendlyChallenge) {
            $this->logger->info('[friendly] resolve message for a challenge that no longer exists', [
                'challengeId' => $message->challengeId,
            ]);

            return;
        }

        if (!$this->friendlyService->resolveIfClaimable($challenge, new \DateTimeImmutable())) {
            $this->logger->info('[friendly] resolve skipped — claimed elsewhere or no longer accepted', [
                'challengeId' => $message->challengeId,
                'status'      => $challenge->getStatus()->value,
            ]);
        }
    }
}
