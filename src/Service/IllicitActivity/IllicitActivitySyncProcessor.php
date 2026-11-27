<?php

declare(strict_types=1);

namespace App\Service\IllicitActivity;

use App\Entity\Club;
use App\Entity\IllicitActivity;
use App\Entity\SyncRecord;
use App\Repository\IllicitActivityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores a sync's full `illicitActivity` list (2026-11-27). Called by SyncService::process().
 *
 * Upserts by (club, clientId), never deletes, and marks rows the list left out as missing.
 * Touches no balance, ledger or owner state. A null list ("field not sent") changes nothing.
 */
final class IllicitActivitySyncProcessor
{
    public function __construct(
        private readonly IllicitActivityValidator $validator,
        private readonly IllicitActivityRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(Club $club, ?array $raw, ?SyncRecord $syncRecord, \DateTimeImmutable $seenAt): void
    {
        if ($raw === null) {
            return;
        }

        $result = $this->validator->validate($raw);
        if ($result['dropped'] !== [] || $result['trimmed'] > 0) {
            $this->logger->warning('sync.illicit_activity.dropped', [
                'clubId'  => (string) $club->getId(),
                'dropped' => $result['dropped'],
                'trimmed' => $result['trimmed'],
            ]);
        }

        $existing = $this->repository->findByClubKeyedByClientId($club);
        $reported = [];
        foreach ($result['records'] as $report) {
            $reported[$report->clientId] = true;
            $row = $existing[$report->clientId] ?? null;
            if ($row === null) {
                $row = new IllicitActivity($club, $report->clientId, $report->kind, $seenAt);
                $this->em->persist($row);
            } elseif ($row->getKind() !== $report->kind) {
                $this->logger->warning('sync.illicit_activity.kind_changed', [
                    'clubId' => (string) $club->getId(), 'clientId' => $report->clientId,
                ]);
                continue;
            }
            $row->applyReport($report, $seenAt, $syncRecord);
        }

        // A record that was sent but failed validation is not "missing": it is still on the device.
        foreach ($raw as $item) {
            if (\is_array($item) && \is_string($item['id'] ?? null)) {
                $reported[$item['id']] = true;
            }
        }
        foreach ($existing as $clientId => $row) {
            if (!isset($reported[$clientId])) {
                $row->markMissing($seenAt);
            }
        }
    }
}
