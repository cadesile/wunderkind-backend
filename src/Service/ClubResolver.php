<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\User;
use App\Exception\ClubMismatchException;
use App\Repository\ClubRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves which club a request is about.
 *
 * A user can own more than one club — Club::$user is ManyToOne and
 * ClubInitializationService creates a club per new game, so each save slot gets its
 * own. ClubRepository::findByUser() answers "the newest one", which is only ever a
 * guess. Every request that knows which club it means should say so:
 *
 *  - Deferred payloads (sync, conclude-season) carry `clubId` in the BODY, captured
 *    when the payload was built. They queue on the device and can be delivered long
 *    after the user has switched saves, so resolving them at delivery time would
 *    attribute them to the wrong club.
 *  - Live requests carry the `X-Club-Id` header, read from the loaded save.
 *
 * When no id is supplied we fall back to findByUser(): clients released before this
 * header existed must keep working unchanged.
 */
class ClubResolver
{
    public const CLUB_ID_HEADER = 'X-Club-Id';

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly RequestStack   $requestStack,
    ) {}

    /**
     * Resolve an explicitly named club, falling back to the user's newest when
     * $clubId is null or blank.
     *
     * @throws ClubMismatchException if the id is malformed, unknown, or owned by someone else
     */
    public function resolve(User $user, ?string $clubId = null): ?Club
    {
        $clubId = $clubId !== null ? trim($clubId) : null;
        if ($clubId === null || $clubId === '') {
            return $this->clubRepository->findByUser($user);
        }

        if (!Uuid::isValid($clubId)) {
            throw new ClubMismatchException($clubId);
        }

        $club = $this->clubRepository->find(Uuid::fromString($clubId));
        if ($club === null || $club->getUser()->getId() !== $user->getId()) {
            throw new ClubMismatchException($clubId);
        }

        return $club;
    }

    /**
     * Resolve using the current request's X-Club-Id header. For live endpoints,
     * where the loaded save is whatever the client is looking at right now.
     *
     * @throws ClubMismatchException if the header names a club this user does not own
     */
    public function resolveFromRequest(User $user): ?Club
    {
        $request = $this->requestStack->getCurrentRequest();

        return $this->resolve($user, $request?->headers->get(self::CLUB_ID_HEADER));
    }
}
