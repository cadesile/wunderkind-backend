<?php

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Enum\MessageSenderType;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\InboxService;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Registration idempotency follows AdminMessageService's MessageDelivery upsert
 * pattern: a raw INSERT ... ON CONFLICT, not ORM flush+catch (Doctrine
 * closes the EntityManager on a failed flush). Capacity-fill lock takes a
 * SELECT ... FOR UPDATE on the ActiveCompetition row before the insert, to serialize
 * concurrent "last slot" registrations.
 */
class CompetitionRegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionLockService $lockService,
        private readonly ?CompetitionRoundRepository $roundRepository = null,
        private readonly ?InboxService $inboxService = null,
        private readonly ?PushNotificationService $pushNotificationService = null,
    ) {}

    /**
     * Computes the starting-XI OVR (rounded integer mean of currentAbility across the 11 players).
     *
     * @param array<string, mixed> $snapshotJson
     */
    public static function computeStartingXiOvr(array $snapshotJson): int
    {
        $players = array_values(array_filter($snapshotJson['players'] ?? [], 'is_array'));
        if ($players === []) {
            if (is_numeric($snapshotJson['club']['ovr'] ?? null)) {
                return (int) round((float) $snapshotJson['club']['ovr']);
            }

            return 0;
        }

        $sum   = 0.0;
        $count = 0;
        foreach (array_slice($players, 0, SnapshotValidator::REQUIRED_PLAYERS) as $player) {
            if (is_numeric($player['currentAbility'] ?? null)) {
                $sum += (float) $player['currentAbility'];
                $count++;
            }
        }

        return $count > 0 ? (int) round($sum / $count) : 0;
    }

    /**
     * @param array<string, mixed> $snapshotJson
     * @return array{entrant: CompetitionEntrant, wasNewRegistration: bool}
     */
    public function register(ActiveCompetition $activeCompetition, Club $club, array $snapshotJson): array
    {
        if (!isset($snapshotJson['club']) || !is_array($snapshotJson['club'])) {
            $snapshotJson['club'] = [];
        }
        $snapshotJson['club']['ovr'] = self::computeStartingXiOvr($snapshotJson);

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            // Lock the instance row first so two simultaneous "last slot" registrations
            // can't both believe they filled it.
            $lockedRow = $connection->fetchAssociative(
                'SELECT id, status, entrant_capacity FROM active_competition WHERE id = :id FOR UPDATE',
                ['id' => $activeCompetition->getId()->toRfc4122()],
            );

            $existing = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);
            if ($existing !== null && $existing->getStatus() !== CompetitionEntrantStatus::WITHDRAWN) {
                // Idempotent replay: this club already has an active registration here.
                $connection->commit();

                return ['entrant' => $existing, 'wasNewRegistration' => false];
            }

            if (($lockedRow['status'] ?? null) !== ActiveCompetitionStatus::REGISTERING->value) {
                throw new \DomainException('registration_closed');
            }

            $currentCount = $this->entrantRepository->countForCompetition($activeCompetition);
            if ($currentCount >= (int) ($lockedRow['entrant_capacity'] ?? $activeCompetition->getEntrantCapacity())) {
                throw new \DomainException('competition_full');
            }

            $entryFeePence = $activeCompetition->getTemplate()->getEntryFeePence();
            if ($entryFeePence > 0) {
                $feeDeducted = $connection->executeStatement(
                    'UPDATE club SET balance = balance - :fee WHERE id = :clubId AND balance >= :fee',
                    [
                        'fee'    => $entryFeePence,
                        'clubId' => $club->getId()->toRfc4122(),
                    ],
                );
                if ($feeDeducted === 0) {
                    throw new \DomainException('insufficient_funds');
                }
            }

            $id       = new UuidV7();
            $now      = new \DateTimeImmutable();
            $affected = $connection->executeStatement(
                <<<'SQL'
                INSERT INTO competition_entrant
                    (id, active_competition_id, club_id, seed, status, snapshot_json, snapshot_locked_at, snapshot_version, registered_at, eliminated_in_round_id)
                VALUES (:id, :competitionId, :clubId, 0, :status, :snapshotJson, NULL, 1, :registeredAt, NULL)
                ON CONFLICT (active_competition_id, club_id) DO UPDATE
                    SET status = EXCLUDED.status,
                        snapshot_json = EXCLUDED.snapshot_json,
                        registered_at = EXCLUDED.registered_at,
                        snapshot_version = competition_entrant.snapshot_version + 1,
                        snapshot_locked_at = NULL
                  WHERE competition_entrant.status = :withdrawnStatus
                SQL,
                [
                    'id'              => $id->toRfc4122(),
                    'competitionId'   => $activeCompetition->getId()->toRfc4122(),
                    'clubId'          => $club->getId()->toRfc4122(),
                    'status'          => CompetitionEntrantStatus::REGISTERED->value,
                    'snapshotJson'    => json_encode($snapshotJson, JSON_THROW_ON_ERROR),
                    'registeredAt'    => $now->format('Y-m-d H:i:s'),
                    'withdrawnStatus' => CompetitionEntrantStatus::WITHDRAWN->value,
                ],
            );

            if ($affected === 0) {
                $connection->commit();
                $existing = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);

                return ['entrant' => $existing, 'wasNewRegistration' => false];
            }

            if ($entryFeePence > 0) {
                $this->em->refresh($club);
            }

            // Reload so the ORM sees the FOR-UPDATE-locked row's current state — another
            // request may have changed it since $activeCompetition was first loaded.
            $this->em->refresh($activeCompetition);

            $entrant = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);
            if ($entrant !== null) {
                $this->em->refresh($entrant);
            }

            $count = $this->entrantRepository->countForCompetition($activeCompetition);
            if ($count >= $activeCompetition->getEntrantCapacity() && $activeCompetition->getStatus() === ActiveCompetitionStatus::REGISTERING) {
                $this->lockService->lock($activeCompetition);
                $this->em->flush();
            }

            $connection->commit();

            return ['entrant' => $entrant, 'wasNewRegistration' => true];
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Withdraws a club from a REGISTERING competition, keeping the entrant row with
     * status = 'withdrawn' (so viewer.relation === 'withdrawn' works) and refunding
     * entryFeePence to Club.balance in the same transaction.
     */
    public function withdraw(ActiveCompetition $activeCompetition, Club $club): CompetitionEntrant
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $lockedRow = $connection->fetchAssociative(
                'SELECT id, status FROM active_competition WHERE id = :id FOR UPDATE',
                ['id' => $activeCompetition->getId()->toRfc4122()],
            );

            if (($lockedRow['status'] ?? null) !== ActiveCompetitionStatus::REGISTERING->value) {
                throw new \DomainException('withdrawal_closed');
            }

            $entrant = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);
            if ($entrant === null || $entrant->getStatus() === CompetitionEntrantStatus::WITHDRAWN) {
                throw new \DomainException('not_registered');
            }

            $affected = $connection->executeStatement(
                <<<'SQL'
                UPDATE competition_entrant
                   SET status = :withdrawn,
                       seed = 0
                 WHERE id = :id
                   AND status = :registered
                SQL,
                [
                    'withdrawn'  => CompetitionEntrantStatus::WITHDRAWN->value,
                    'id'         => $entrant->getId()->toRfc4122(),
                    'registered' => CompetitionEntrantStatus::REGISTERED->value,
                ],
            );

            if ($affected === 0) {
                throw new \DomainException('withdrawal_closed');
            }

            $entryFeePence = $activeCompetition->getTemplate()->getEntryFeePence();
            if ($entryFeePence > 0) {
                $connection->executeStatement(
                    'UPDATE club SET balance = balance + :fee WHERE id = :clubId',
                    [
                        'fee'    => $entryFeePence,
                        'clubId' => $club->getId()->toRfc4122(),
                    ],
                );
                $this->em->refresh($club);
            }

            $this->em->refresh($entrant);
            $this->em->refresh($activeCompetition);
            $connection->commit();

            return $entrant;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Cancels a competition and refunds entryFeePence to every non-withdrawn entrant in the same transaction.
     */
    public function cancel(ActiveCompetition $activeCompetition, string $reason = 'cancelled_by_admin'): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeQuery(
                'SELECT id FROM active_competition WHERE id = :id FOR UPDATE',
                ['id' => $activeCompetition->getId()->toRfc4122()],
            );
            $this->em->refresh($activeCompetition);

            if (in_array($activeCompetition->getStatus(), [ActiveCompetitionStatus::COMPLETED, ActiveCompetitionStatus::CANCELLED], true)) {
                throw new \DomainException('cannot_cancel');
            }

            $entrants      = $this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition);
            $entryFeePence = $activeCompetition->getTemplate()->getEntryFeePence();
            $now           = new \DateTimeImmutable();

            if ($entryFeePence > 0) {
                foreach ($entrants as $entrant) {
                    $entrantClub = $entrant->getClub();
                    $connection->executeStatement(
                        'UPDATE club SET balance = balance + :fee WHERE id = :clubId',
                        [
                            'fee'    => $entryFeePence,
                            'clubId' => $entrantClub->getId()->toRfc4122(),
                        ],
                    );
                    $this->em->refresh($entrantClub);
                }
            }

            if ($this->roundRepository !== null) {
                foreach ($this->roundRepository->findByCompetitionOrderedByIndex($activeCompetition) as $round) {
                    if ($round->getStatus() !== CompetitionRoundStatus::RESULTS_PUBLISHED) {
                        $round->setStatus(CompetitionRoundStatus::CANCELLED);
                    }
                }
            }

            $activeCompetition->setStatus(ActiveCompetitionStatus::CANCELLED);
            $activeCompetition->setCancelledAt($now);
            $activeCompetition->setCancellationReason($reason);

            if ($this->inboxService !== null) {
                foreach ($entrants as $entrant) {
                    $this->inboxService->createMessage(
                        club: $entrant->getClub(),
                        senderType: MessageSenderType::COMPETITION,
                        senderName: 'Tournament Association',
                        subject: sprintf('Tournament Cancelled: %s', $activeCompetition->getTemplate()->getName()),
                        body: $entryFeePence > 0
                            ? sprintf('The %s was cancelled (%s). Your entry fee has been refunded to your club balance.', $activeCompetition->getTemplate()->getName(), $reason)
                            : sprintf('The %s was cancelled (%s).', $activeCompetition->getTemplate()->getName(), $reason),
                        offerData: [
                            'type' => 'COMPETITION_CANCELLED',
                            'link' => ['kind' => 'competition', 'id' => (string) $activeCompetition->getId()],
                        ],
                        relatedEntityType: 'active_competition',
                        relatedEntityId: (string) $activeCompetition->getId(),
                    );
                }
            }

            $this->em->flush();
            $connection->commit();

            if ($this->pushNotificationService !== null && $entrants !== []) {
                $userIds = array_values(array_unique(array_map(
                    static fn (CompetitionEntrant $e) => (string) $e->getClub()->getUser()->getId(),
                    $entrants,
                )));
                $this->pushNotificationService->notifyUsers(
                    $userIds,
                    'Tournament cancelled',
                    sprintf('%s has been cancelled.', $activeCompetition->getTemplate()->getName()),
                    ['type' => 'COMPETITION_CANCELLED', 'competitionId' => (string) $activeCompetition->getId()],
                );
            }
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Raw SQL UPDATE — snapshotJson mixes string/int values, so Doctrine's json
     * dirty-check would silently skip an ORM-level setSnapshotJson()+flush() update.
     *
     * @param array<string, mixed> $snapshotJson
     */
    public function resubmit(CompetitionEntrant $entrant, array $snapshotJson): void
    {
        if (!isset($snapshotJson['club']) || !is_array($snapshotJson['club'])) {
            $snapshotJson['club'] = [];
        }
        $snapshotJson['club']['ovr'] = self::computeStartingXiOvr($snapshotJson);

        $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE competition_entrant
               SET snapshot_json = :snapshotJson,
                   snapshot_version = snapshot_version + 1,
                   snapshot_locked_at = NULL
             WHERE id = :id
            SQL,
            [
                'snapshotJson' => json_encode($snapshotJson, JSON_THROW_ON_ERROR),
                'id'           => $entrant->getId()->toRfc4122(),
            ],
        );
    }
}
