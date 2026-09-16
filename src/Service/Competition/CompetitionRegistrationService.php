<?php

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Repository\Competition\CompetitionEntrantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Registration idempotency follows AdminMessageService's MessageDelivery upsert
 * pattern: a raw INSERT ... ON CONFLICT DO NOTHING, not ORM flush+catch (Doctrine
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
    ) {}

    /**
     * @param array<string, mixed> $snapshotJson
     * @return array{entrant: CompetitionEntrant, wasNewRegistration: bool}
     */
    public function register(ActiveCompetition $activeCompetition, Club $club, array $snapshotJson): array
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            // Lock the instance row first so two simultaneous "last slot" registrations
            // can't both believe they filled it.
            $connection->executeQuery(
                'SELECT id FROM active_competition WHERE id = :id FOR UPDATE',
                ['id' => $activeCompetition->getId()->toRfc4122()],
            );

            $id       = new UuidV7();
            $now      = new \DateTimeImmutable();
            $affected = $connection->executeStatement(
                <<<'SQL'
                INSERT INTO competition_entrant
                    (id, active_competition_id, club_id, seed, status, snapshot_json, snapshot_locked_at, snapshot_version, registered_at, eliminated_in_round_id)
                VALUES (:id, :competitionId, :clubId, 0, :status, :snapshotJson, NULL, 1, :registeredAt, NULL)
                ON CONFLICT (active_competition_id, club_id) DO NOTHING
                SQL,
                [
                    'id'            => $id->toRfc4122(),
                    'competitionId' => $activeCompetition->getId()->toRfc4122(),
                    'clubId'        => $club->getId()->toRfc4122(),
                    'status'        => CompetitionEntrantStatus::REGISTERED->value,
                    'snapshotJson'  => json_encode($snapshotJson, JSON_THROW_ON_ERROR),
                    'registeredAt'  => $now->format('Y-m-d H:i:s'),
                ],
            );

            if ($affected === 0) {
                // Idempotent replay: this club already has an entrant here (e.g. a
                // dropped-response retry). Not an error.
                $connection->commit();
                $existing = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);

                return ['entrant' => $existing, 'wasNewRegistration' => false];
            }

            // Reload so the ORM sees the FOR-UPDATE-locked row's current state — another
            // request may have changed it since $activeCompetition was first loaded.
            $this->em->refresh($activeCompetition);

            $entrant = $this->entrantRepository->find($id);

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
     * Raw SQL UPDATE — snapshotJson mixes string/int values, so Doctrine's json
     * dirty-check would silently skip an ORM-level setSnapshotJson()+flush() update.
     *
     * @param array<string, mixed> $snapshotJson
     */
    public function resubmit(CompetitionEntrant $entrant, array $snapshotJson): void
    {
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
