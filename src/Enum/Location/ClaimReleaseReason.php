<?php

namespace App\Enum\Location;

/**
 * Why a plot claim ended.
 *
 * Recorded rather than inferred, because the reasons are not interchangeable: a sell-back pays
 * the player, a dormancy reclaim pays them at a (possibly different) valuation, and a support
 * reversal is an admin undoing something. "The claim is gone" is not enough information to
 * answer a support ticket about money.
 */
enum ClaimReleaseReason: string
{
    /** The player chose to sell the plot back. */
    case SOLD_BACK = 'sold_back';

    /** Reclaimed after the club went inactive for 30 days. */
    case DORMANCY = 'dormancy';

    /** The club was deleted while still holding the plot. */
    case CLUB_DELETED = 'club_deleted';

    /** The whole account was deleted. */
    case ACCOUNT_DELETED = 'account_deleted';

    /** An admin unwound the claim. */
    case SUPPORT_REVERSAL = 'support_reversal';

    public function label(): string
    {
        return match ($this) {
            self::SOLD_BACK        => 'Sold back',
            self::DORMANCY         => 'Reclaimed (dormant)',
            self::CLUB_DELETED     => 'Club deleted',
            self::ACCOUNT_DELETED  => 'Account deleted',
            self::SUPPORT_REVERSAL => 'Support reversal',
        };
    }

    /** Whether the player should be credited for the release. */
    public function paysOut(): bool
    {
        return $this === self::SOLD_BACK || $this === self::DORMANCY || $this === self::CLUB_DELETED;
    }
}
