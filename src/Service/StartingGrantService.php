<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\UserLedgerEntryType;
use App\Repository\StarterConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * The account's opening credit — granted once, when the player creates their owner avatar.
 *
 * WHY THE AVATAR AND NOT CLUB CREATION. The grant is a fact about the ACCOUNT, and an account
 * owns many clubs over its life. Hanging it off club creation would grant it again per club;
 * hanging it off registration would grant it to accounts that never start playing. Avatar
 * creation is the first point at which there is a player rather than just a login.
 *
 * IDEMPOTENT, AND THAT IS WHAT MAKES THE HOOK SAFE. POST /api/owner-avatar is a partial-update
 * endpoint the client calls repeatedly — renaming, re-rolling a look — so this must not be a
 * "first call" heuristic. UserLedgerService::append() dedupes on
 * (sourceKind, sourceRef, type) = ('starting_grant', userId, STARTING_GRANT), so the hundredth
 * avatar save writes nothing and returns the original entry. No flag column, no ordering
 * assumption.
 *
 * THE AMOUNT IS DETERMINISTIC, not random — see StarterConfig::resolveUserStartingBalancePence().
 * Support has to be able to reproduce an opening balance after the fact, and the figure has to
 * be stable whether or not the grant row exists yet.
 *
 * NEVER THROWS INTO THE CALLER. Avatar creation must not fail because a grant could not be
 * written: the player would be stuck on the one screen they cannot skip, over money they have
 * not tried to spend yet. A failure is logged and the next avatar save retries it, which the
 * idempotency above makes free.
 */
final class StartingGrantService
{
    /** Shared by the ledger row's sourceKind and its description. */
    public const SOURCE_KIND = 'starting_grant';

    public function __construct(
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly UserLedgerService       $ledgerService,
        private readonly LoggerInterface         $logger,
    ) {}

    /** What this account's opening credit is, or would be. Reads nothing but config. */
    public function amountFor(User $user): int
    {
        return $this->starterConfigRepository->getConfig()
            ->resolveUserStartingBalancePence((string) $user->getId());
    }

    /**
     * Grant the opening credit if it has not been granted already.
     *
     * Returns the entry — the existing one on a repeat call — or null when the configured range
     * resolves to zero (a deliberate "no grant" setting) or the write failed.
     */
    public function grantIfDue(User $user): ?UserLedger
    {
        $amountPence = $this->amountFor($user);

        if ($amountPence <= 0) {
            // A zero range is a legitimate configuration, not an error: an operator can turn
            // the grant off by setting both ends to 0.
            return null;
        }

        try {
            return $this->ledgerService->append(
                $user,
                // No club. The grant predates any club and must outlive every one of them.
                null,
                UserLedgerEntryType::STARTING_GRANT,
                $amountPence,
                self::SOURCE_KIND,
                (string) $user->getId(),
                'Opening account credit',
            );
        } catch (\Throwable $e) {
            $this->logger->error('Could not write starting grant', [
                'userId'      => (string) $user->getId(),
                'amountPence' => $amountPence,
                'exception'   => $e,
            ]);

            return null;
        }
    }
}
