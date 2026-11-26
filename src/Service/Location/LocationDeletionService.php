<?php

declare(strict_types=1);

namespace App\Service\Location;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Deletes towns that enrichment could not place, from the "Needs a human" review queue.
 *
 * WHY DELETE AT ALL, when LocationStatus says "retired, never deleted". That rule exists for
 * towns that clubs already sit on. A town pulled in by mistake — "Nanjing Placeholder" filed
 * under Canada — has never been played on and never will be: it cannot be enriched, so it has
 * no coordinates and no land value, and it sits on the review queue forever. Retiring it would
 * only hide it from the queue while leaving a row nobody wants. So deletion is allowed, but
 * ONLY where nothing real points at the town, and every case that would orphan or destroy
 * real data is refused instead.
 *
 * WHAT BLOCKS A DELETE (each is real data that must not be lost or orphaned):
 *  - any plot_claim, released or not — claims carry real money and their history is the
 *    ledger's evidence; the FK is ON DELETE RESTRICT for that reason;
 *  - a user club with this as its home town (club.home_location_id would silently go NULL);
 *  - an NPC club placed in this town (npc_club.location_id would go NULL and world packs
 *    snapshot the town's name for it);
 *  - an open plot hold — a player is part-way through buying land here right now.
 *
 * WHAT GOES WITH IT, deliberately: its unclaimed plots, spent/expired holds on them, its
 * enrichment attempts, stadium estimates and rival rows. Holds go first, explicitly, because
 * plot_hold → stadium_estimate is ON DELETE RESTRICT and would otherwise fight the location's
 * own cascade. Towns that listed the deleted town as a rival are marked stale so the next
 * rivals pass refills their set, and the country is re-banded so the city-size pyramid does
 * not keep a gap where the town was.
 *
 * ONLY review-queue towns (FAILED / NEEDS_REVIEW) can be deleted here. The route takes ids
 * from a POST body, and this is the guard that stops a crafted request deleting a good town.
 */
final class LocationDeletionService
{
    /** The enrichment statuses the review queue lists, and so the only ones deletable here. */
    public const DELETABLE_STATUSES = ['failed', 'needs_review'];

    public function __construct(
        private readonly Connection                    $connection,
        private readonly LocationClassificationService $classificationService,
    ) {}

    /**
     * Delete each given town that is safe to delete, and say why the rest were kept.
     *
     * @param list<string> $ids location ids
     *
     * @return array{deleted: list<string>, blocked: array<string, string>} deleted town names,
     *         and town name => reason for each one kept
     */
    public function deleteUnenrichable(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id): bool => \is_string($id) && $id !== '')));

        if ($ids === []) {
            return ['deleted' => [], 'blocked' => []];
        }

        // Ids that are not valid uuids would make Postgres throw on the cast; drop them here.
        $ids = array_values(array_filter($ids, static fn (string $id): bool => (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id,
        )));

        if ($ids === []) {
            return ['deleted' => [], 'blocked' => []];
        }

        return $this->connection->transactional(function (Connection $c) use ($ids): array {
            // Lock the rows so a claim or hold cannot land between the check and the delete.
            $rows = $c->fetchAllAssociative(
                'SELECT id, town, country_code, enrichment_status FROM location WHERE id IN (:ids) FOR UPDATE',
                ['ids' => $ids],
                ['ids' => ArrayParameterType::STRING],
            );

            $deleted   = [];
            $blocked   = [];
            $deletable = [];
            $countries = [];

            foreach ($rows as $row) {
                $reason = $this->blockReason($c, $row);

                if ($reason !== null) {
                    $blocked[(string) $row['town']] = $reason;

                    continue;
                }

                $deletable[] = (string) $row['id'];
                $deleted[]   = (string) $row['town'];
                $countries[(string) $row['country_code']] = true;
            }

            if ($deletable !== []) {
                $this->deleteRows($c, $deletable);
            }

            foreach (array_keys($countries) as $country) {
                $this->classificationService->reclassifyCountry((string) $country);
            }

            return ['deleted' => $deleted, 'blocked' => $blocked];
        });
    }

    /**
     * Delete one town from the Locations CRUD, enriched or not, unless a plot there is taken.
     *
     * LOOSER THAN deleteUnenrichable() ON PURPOSE (Courtney, 2026-11-26): a town is freely
     * deletable, whatever its enrichment status, so long as nothing a player owns sits on it.
     * Blocked by a player's claim (active, or released history, which the ledger still points
     * at), a user club homed there, or an open hold. NPC clubs seated there are unseated by
     * the FK's own SET NULL, the same recoverable loss the plot reaper accepts; re-run the
     * seating backfill to give them a new home.
     *
     * @return string|null why it was kept, or null when it was deleted
     */
    public function deleteTown(string $id): ?string
    {
        return $this->connection->transactional(function (Connection $c) use ($id): ?string {
            $row = $c->fetchAssociative(
                'SELECT id, town, country_code FROM location WHERE id = :id FOR UPDATE',
                ['id' => $id],
            );

            if ($row === false) {
                return 'it no longer exists';
            }

            $reason = $this->takenReason($c, (string) $row['id']);

            if ($reason !== null) {
                return $reason;
            }

            $this->deleteRows($c, [(string) $row['id']]);
            $this->classificationService->reclassifyCountry((string) $row['country_code']);

            return null;
        });
    }

    /** Why a player still holds part of this town, or null. */
    private function takenReason(Connection $c, string $locationId): ?string
    {
        $id = ['id' => $locationId];

        $active = (int) $c->fetchOne('SELECT COUNT(*) FROM plot_claim WHERE location_id = :id AND released_at IS NULL', $id);
        if ($active > 0) {
            return \sprintf('%d of its plots are taken by a player — retire it instead', $active);
        }

        $released = (int) $c->fetchOne('SELECT COUNT(*) FROM plot_claim WHERE location_id = :id', $id);
        if ($released > 0) {
            return \sprintf('it has %d released plot claim(s) whose money history the ledger still points at — retire it instead', $released);
        }

        $clubs = (int) $c->fetchOne('SELECT COUNT(*) FROM club WHERE home_location_id = :id', $id);
        if ($clubs > 0) {
            return \sprintf('%d player club(s) call it home — retire it instead', $clubs);
        }

        $openHolds = (int) $c->fetchOne(
            'SELECT COUNT(*) FROM plot_hold h JOIN land_plot p ON p.id = h.plot_id
              WHERE p.location_id = :id
                AND h.released_at IS NULL AND h.consumed_at IS NULL AND h.expires_at > NOW()',
            $id,
        );
        if ($openHolds > 0) {
            return 'a player is holding a plot there right now — try again once the hold expires';
        }

        return null;
    }

    /**
     * Why this town must be kept, or null if nothing real depends on it.
     *
     * @param array<string, mixed> $row
     */
    private function blockReason(Connection $c, array $row): ?string
    {
        if (!\in_array($row['enrichment_status'], self::DELETABLE_STATUSES, true)) {
            return 'it is not on the review queue (only FAILED or NEEDS_REVIEW towns can be deleted here)';
        }

        $id = ['id' => (string) $row['id']];

        $claims = (int) $c->fetchOne('SELECT COUNT(*) FROM plot_claim WHERE location_id = :id', $id);
        if ($claims > 0) {
            return \sprintf('it has %d plot claim(s) carrying real money — retire it instead', $claims);
        }

        $clubs = (int) $c->fetchOne('SELECT COUNT(*) FROM club WHERE home_location_id = :id', $id);
        if ($clubs > 0) {
            return \sprintf('%d club(s) call it home — retire it instead', $clubs);
        }

        $npcClubs = (int) $c->fetchOne('SELECT COUNT(*) FROM npc_club WHERE location_id = :id', $id);
        if ($npcClubs > 0) {
            return \sprintf('%d NPC club(s) are placed there — retire it instead', $npcClubs);
        }

        $openHolds = (int) $c->fetchOne(
            'SELECT COUNT(*) FROM plot_hold h JOIN land_plot p ON p.id = h.plot_id
              WHERE p.location_id = :id
                AND h.released_at IS NULL AND h.consumed_at IS NULL AND h.expires_at > NOW()',
            $id,
        );
        if ($openHolds > 0) {
            return 'a player is holding a plot there right now — try again once the hold expires';
        }

        return null;
    }

    /** @param list<string> $ids */
    private function deleteRows(Connection $c, array $ids): void
    {
        $params = ['ids' => $ids];
        $types  = ['ids' => ArrayParameterType::STRING];

        // Neighbours that named a deleted town as a rival need their set recomputed.
        $c->executeStatement(
            'UPDATE location SET rivals_computed_at = NULL
              WHERE id IN (SELECT location_id FROM location_rival WHERE rival_location_id IN (:ids))
                AND id NOT IN (:ids)',
            $params,
            $types,
        );

        $c->executeStatement(
            'DELETE FROM plot_hold WHERE plot_id IN (SELECT id FROM land_plot WHERE location_id IN (:ids))',
            $params,
            $types,
        );
        $c->executeStatement('DELETE FROM land_plot WHERE location_id IN (:ids)', $params, $types);

        // Attempts, rivals (both directions) and stadium estimates cascade from here.
        $c->executeStatement('DELETE FROM location WHERE id IN (:ids)', $params, $types);
    }
}
