<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stamps User::$tokenVersion into every access token as a `tv` claim, and
 * rejects any token whose claim no longer matches the account.
 *
 * This is what makes "kill session" immediate on a stateless firewall: there is
 * no session and no per-token denylist, so the only way to retire tokens that
 * are already out in the wild is to version them and move the version on.
 *
 * Two deliberate properties, both about not locking out the whole user base:
 *
 *  - An ABSENT `tv` claim counts as 0, the same value every existing row gets
 *    from the migration's DEFAULT. Tokens minted before this shipped therefore
 *    stay valid for accounts nobody has killed, while a killed account's older
 *    tokens (version >= 1) still stop working. No grandfather window.
 *  - Anything unexpected fails OPEN — unknown identity claim, user not found,
 *    non-User principal. A bug in here would otherwise 401 every API request at
 *    once; rejecting is reserved for the one case we can state positively,
 *    which is "this account's version has moved past the token's".
 *    An unknown user is the user provider's 401 to issue, not ours.
 */
class JwtTokenVersionSubscriber implements EventSubscriberInterface
{
    public const CLAIM = 'tv';

    /**
     * Reads through DBAL rather than the ORM on purpose: this runs on every
     * authenticated API request and only ever needs one integer, so there is no
     * reason to hydrate a User. `user` is a reserved word in PostgreSQL and
     * must stay quoted.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_CREATED => 'onJwtCreated',
            Events::JWT_DECODED => 'onJwtDecoded',
        ];
    }

    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();
        $payload[self::CLAIM] = $user->getTokenVersion();
        $event->setData($payload);
    }

    public function onJwtDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();

        $idClaim = $this->jwtManager->getUserIdClaim();
        if ($idClaim === null || !isset($payload[$idClaim]) || !is_string($payload[$idClaim])) {
            return;
        }

        $stored = $this->connection->fetchOne(
            'SELECT token_version FROM "user" WHERE email = :email',
            ['email' => $payload[$idClaim]],
        );

        // Unknown account — let the user provider decide, don't pre-empt it.
        if ($stored === false || $stored === null) {
            return;
        }

        if ((int) ($payload[self::CLAIM] ?? 0) !== (int) $stored) {
            $event->markAsInvalid();
        }
    }
}
