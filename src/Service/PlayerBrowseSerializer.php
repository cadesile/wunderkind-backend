<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;

/**
 * Pool-browsing player shape (GET /api/scout/search, GET /api/players/foreign).
 *
 * This is now a thin guardians-on preset over PlayerSnapshotSerializer, which owns the
 * single canonical shape — see that class for why the world-pack snapshot became the
 * model and how the two former shapes were unioned without removing a key.
 *
 * Kept as its own class rather than inlined at the two call sites because it carries a
 * real decision (browsing includes guardians; world-pack generation deliberately does
 * not), and because both controllers already type-hint it.
 */
class PlayerBrowseSerializer
{
    public function __construct(
        private readonly PlayerSnapshotSerializer $snapshots = new PlayerSnapshotSerializer(),
    ) {}

    public function serialize(Player $p): array
    {
        return $this->snapshots->serialize($p, withGuardians: true);
    }
}
