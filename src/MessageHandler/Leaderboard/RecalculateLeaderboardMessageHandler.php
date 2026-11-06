<?php

declare(strict_types=1);

namespace App\MessageHandler\Leaderboard;

use App\Enum\LeaderboardCategory;
use App\Message\Leaderboard\RecalculateLeaderboardMessage;
use App\Service\LeaderboardCalculationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RecalculateLeaderboardMessageHandler
{
    public function __construct(
        private readonly LeaderboardCalculationService $leaderboards,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RecalculateLeaderboardMessage $message): void
    {
        $category = LeaderboardCategory::tryFrom($message->category);

        if (null === $category) {
            // A category that no longer exists is a message outliving a
            // deploy, not a failure worth retrying — retrying cannot make the
            // enum case come back.
            $this->logger->warning('Skipping leaderboard recalculation for unknown category.', [
                'category' => $message->category,
                'period'   => $message->period,
            ]);

            return;
        }

        // recalculate() already returns 0 for a closed period rather than
        // resurrecting a pruned board, so no guard is needed here.
        $written = $this->leaderboards->recalculate($category, $message->period);

        $this->logger->info('Recalculated leaderboard.', [
            'category' => $category->value,
            'period'   => $message->period,
            'entries'  => $written,
        ]);
    }
}
