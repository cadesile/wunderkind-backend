<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which accounts a BULK delete must never touch, and why.
 *
 * AccountDeletionService deletes whatever it is handed. That is right for the single,
 * deliberate "delete this user" button, and wrong for anything that selects accounts by
 * filter — a ticked "select all matching" over a loose filter is one click away from
 * erasing someone's go-live buyout credit. So every bulk path (the admin batch delete,
 * the inactive-account prune) asks this first and only deletes what comes back clean.
 *
 * Protected (Courtney, 2026-11-27, go-live cleanup):
 *  - admin: roles carry ROLE_ADMIN (the hand grant described in CLAUDE.md).
 *  - test:  the per-user debug panel is on. That flag is how tester accounts are marked.
 *  - ledger: any user_ledger row. The owner ledger is the player's own money (dividends,
 *            club-sale buyout credit, plot purchases); a user_ledger row cascades away with
 *            the user, so deleting the account deletes the balance.
 *  - plot:  any plot_claim (bought land, active or sold back) or an unexpired plot_hold.
 *            plot_claim.user_id is ON DELETE CASCADE, so the land record would go too.
 *
 * Deliberately set-based: one query per table for the whole selection, so a 1,400-row
 * preview costs four queries, not 5,600.
 */
final class AccountDeletionGuard
{
    public const ADMIN  = 'admin';
    public const TEST   = 'test';
    public const LEDGER = 'ledger';
    public const PLOT   = 'plot';

    /** Human labels, in the order the admin preview lists them. */
    public const LABELS = [
        self::LEDGER => 'Owner ledger history (credit, dividends, buyout)',
        self::PLOT   => 'Owns or is buying a plot',
        self::ADMIN  => 'Admin role',
        self::TEST   => 'Test account (debug panel on)',
    ];

    private const CHUNK = 1000;

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param iterable<User> $users
     *
     * @return array<string, list<string>> user id => reasons (one of the consts), only for
     *                                     protected users; an id absent from the map is safe
     */
    public function protectedReasons(iterable $users): array
    {
        $reasons = [];
        $ids     = [];

        foreach ($users as $user) {
            $id    = (string) $user->getId();
            $ids[] = $id;

            if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $reasons[$id][] = self::ADMIN;
            }
            if ($user->isDebugLoggingEnabled()) {
                $reasons[$id][] = self::TEST;
            }
        }

        $conn = $this->em->getConnection();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $params = ['ids' => $chunk];
            $types  = ['ids' => ArrayParameterType::STRING];

            foreach ($conn->fetchFirstColumn(
                'SELECT DISTINCT user_id FROM user_ledger WHERE user_id IN (:ids)', $params, $types,
            ) as $id) {
                $reasons[(string) $id][] = self::LEDGER;
            }

            foreach ($conn->fetchFirstColumn(
                'SELECT user_id FROM plot_claim WHERE user_id IN (:ids)
                 UNION
                 SELECT user_id FROM plot_hold WHERE user_id IN (:ids) AND expires_at > NOW()',
                $params, $types,
            ) as $id) {
                $reasons[(string) $id][] = self::PLOT;
            }
        }

        return $reasons;
    }

    /**
     * Splits a selection into what may be deleted and what must be kept.
     *
     * @param iterable<User> $users
     *
     * @return array{deletable: list<User>, protected: list<array{user: User, reasons: list<string>}>}
     */
    public function partition(iterable $users): array
    {
        $users   = \is_array($users) ? $users : iterator_to_array($users, false);
        $reasons = $this->protectedReasons($users);

        $deletable = [];
        $protected = [];
        foreach ($users as $user) {
            $r = $reasons[(string) $user->getId()] ?? [];
            if ($r === []) {
                $deletable[] = $user;
            } else {
                $protected[] = ['user' => $user, 'reasons' => array_values(array_unique($r))];
            }
        }

        return ['deletable' => $deletable, 'protected' => $protected];
    }
}
