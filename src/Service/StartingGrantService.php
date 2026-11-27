<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\UserLedgerEntryType;
use App\Repository\StarterConfigRepository;
use App\Repository\UserLedgerRepository;
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

    /**
     * The admin "Gift starter balance" top-up, for an account whose own grant is below the
     * range's maximum. Keyed on the user id like the grant, so it can be written once only.
     */
    public const GIFT_SOURCE_KIND = 'starting_grant_gift';

    public function __construct(
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly UserLedgerService       $ledgerService,
        private readonly LoggerInterface         $logger,
        private readonly UserLedgerRepository    $ledgerRepository,
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

    /**
     * Admin "Gift starter balance" (Courtney, 2026-11-27): bring this account's starting grant
     * up to the MAXIMUM of the configured range, so an existing owner whose club was sold is not
     * behind a new sign-up. Two cases, both written at most once:
     *
     * - No grant yet: the gift IS the grant, written under the grant's own idempotency key at
     *   the maximum. A later avatar save then finds it and grants nothing more.
     * - A grant below the maximum: a top-up for the difference, under GIFT_SOURCE_KIND.
     *
     * Already at or above the maximum, or the grant switched off (max 0): nothing is written.
     *
     * @return array{maxPence: int, grantedPence: int, giftPence: int}
     */
    public function giftPreview(User $user): array
    {
        $config = $this->starterConfigRepository->getConfig();
        $max    = max($config->getUserStartingBalanceMinPence(), $config->getUserStartingBalanceMaxPence());

        $granted = 0;
        foreach ($this->ledgerRepository->findBy(['user' => $user, 'type' => UserLedgerEntryType::STARTING_GRANT]) as $entry) {
            $granted += $entry->getAmountPence();
        }

        return [
            'maxPence'     => $max,
            'grantedPence' => $granted,
            'giftPence'    => max(0, $max - $granted),
        ];
    }

    /** Writes the gift worked out by giftPreview(). Returns null when there is nothing to give. */
    public function giftToMax(User $user): ?UserLedger
    {
        $preview = $this->giftPreview($user);

        if ($preview['giftPence'] <= 0) {
            return null;
        }

        $hasGrant = $this->ledgerRepository->findByBackendSource(
            self::SOURCE_KIND,
            (string) $user->getId(),
            UserLedgerEntryType::STARTING_GRANT,
        ) !== null;

        return $this->ledgerService->append(
            $user,
            null,
            UserLedgerEntryType::STARTING_GRANT,
            $preview['giftPence'],
            $hasGrant ? self::GIFT_SOURCE_KIND : self::SOURCE_KIND,
            (string) $user->getId(),
            $hasGrant ? 'Starting grant top-up (gift)' : 'Opening account credit (gift)',
        );
    }
}
