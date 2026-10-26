<?php

declare(strict_types=1);

namespace App\MessageHandler\Pool;

use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Message\Pool\TopUpStarterPoolMessage;
use App\Repository\PlayerRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Service\MarketPoolService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Generates one chunk of pool entities for one nationality.
 *
 * **It re-reads the count before generating.** The audit sized the queue against the
 * stock it saw at sweep time; by the time the fifth chunk of a 500-player top-up is
 * drained, the first four have already landed, and an admin may have pressed Warm on top
 * of that. Generating the chunk blind would overshoot the floor by however much the queue
 * still holds. Clamping to `target - current` instead makes a redundant delivery a no-op,
 * which is also what makes the whole pipeline safe to re-run: a replayed message, a
 * double-dispatch, or a sweep that fired while chunks were still draining all cost
 * nothing.
 *
 * Exceptions are deliberately *not* swallowed — unlike WarmWorldPackClubMessageHandler,
 * which self-manages retries because an admin progress UI reads its row. There is no row
 * here and nothing is watching: letting the failure reach Messenger puts it on the
 * `failed` transport, where `messenger:failed:retry` can replay it, and the clamp above
 * means replaying is harmless.
 */
#[AsMessageHandler]
final class TopUpStarterPoolMessageHandler
{
    public function __construct(
        private readonly MarketPoolService $pool,
        private readonly PlayerRepository  $playerRepository,
        private readonly StaffRepository   $staffRepository,
        private readonly ScoutRepository   $scoutRepository,
        private readonly LoggerInterface   $logger,
    ) {}

    public function __invoke(TopUpStarterPoolMessage $message): void
    {
        $role    = $message->staffRole === null ? null : StaffRole::from($message->staffRole);
        $current = $this->currentStock($message, $role);
        $toMake  = min($message->count, $message->target - $current);

        $context = [
            'country'     => $message->country,
            'nationality' => $message->nationality,
            'kind'        => $message->kind,
            'role'        => $message->staffRole,
            'current'     => $current,
            'target'      => $message->target,
        ];

        if ($toMake <= 0) {
            $this->logger->info('[starter-pool] chunk skipped, floor already met', $context);

            return;
        }

        match ($message->kind) {
            TopUpStarterPoolMessage::KIND_PLAYERS => $this->pool->generatePlayers(
                $toMake,
                RecruitmentSource::YOUTH_INTAKE,
                $message->nationality,
            ),
            TopUpStarterPoolMessage::KIND_STAFF => $this->pool->generateStaffForRole(
                $role ?? throw new \InvalidArgumentException('A staff top-up needs a staffRole.'),
                $toMake,
                $message->nationality,
            ),
            TopUpStarterPoolMessage::KIND_SCOUTS => $this->pool->generateScouts(
                $toMake,
                $message->nationality,
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown top-up kind "%s".', $message->kind)),
        };

        $this->logger->info('[starter-pool] generated ' . $toMake, $context);
    }

    private function currentStock(TopUpStarterPoolMessage $message, ?StaffRole $role): int
    {
        return match ($message->kind) {
            TopUpStarterPoolMessage::KIND_PLAYERS => $this->playerRepository->countInPoolByNationality($message->nationality),
            TopUpStarterPoolMessage::KIND_STAFF   => $role === null
                ? 0
                : $this->staffRepository->countInPoolByNationalityAndRole($message->nationality, $role),
            TopUpStarterPoolMessage::KIND_SCOUTS  => $this->scoutRepository->countInPool($message->nationality),
            default                               => 0,
        };
    }
}
