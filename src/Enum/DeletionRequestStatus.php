<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Outcome of a web account-deletion request.
 *
 * Every attempt is recorded, not just the successful ones: the rejected rows are
 * what let you tell a store reviewer (or the user) that a request was received
 * and why it did not result in a deletion.
 */
enum DeletionRequestStatus: string
{
    /** Credentials verified and AccountDeletionService completed. */
    case COMPLETED = 'completed';

    /** Email unknown, or password did not match. Both map here deliberately — see the controller. */
    case REJECTED_INVALID_CREDENTIALS = 'rejected_invalid_credentials';

    /** Device-bound guest account; has no password, so it can only be deleted in-app. */
    case REJECTED_GUEST = 'rejected_guest';

    /** Credentials were valid but the deletion itself threw. Needs manual follow-up. */
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED                    => 'Completed',
            self::REJECTED_INVALID_CREDENTIALS => 'Rejected — invalid credentials',
            self::REJECTED_GUEST               => 'Rejected — guest account',
            self::FAILED                       => 'Failed — needs follow-up',
        };
    }

    /** Rows an operator should actually look at. */
    public function needsAttention(): bool
    {
        return $this === self::FAILED;
    }
}
