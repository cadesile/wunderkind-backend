<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Thrown when a request names a club the authenticated user does not own, or one
 * that no longer exists.
 *
 * Deliberately fatal rather than falling back to the user's newest club. That
 * fallback is what caused the original bug: a user can own several clubs (one per
 * save slot — Club::$user is ManyToOne), so "guess the newest" silently wrote one
 * save's progress onto another save's club. A wrong id must fail loudly.
 *
 * 403 rather than 404: the id may well exist, just not for this user, and we do not
 * confirm the existence of other users' clubs.
 */
class ClubMismatchException extends AccessDeniedHttpException
{
    public function __construct(private readonly string $clubId)
    {
        parent::__construct(sprintf('Club "%s" does not belong to this user.', $clubId));
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }
}
