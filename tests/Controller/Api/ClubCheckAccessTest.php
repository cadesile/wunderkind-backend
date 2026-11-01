<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/club/check must answer about club existence, never about the account.
 *
 * Both fixtures here deliberately store NO roles — the legacy shape that locked accounts out
 * (see User::getRoles() and docs/api/role-club-403.md) — so these also prove the role guarantee
 * holds through the real firewall rather than only in the entity.
 *
 * One request per client: loginUser() authenticates exactly once against the stateless JWT
 * firewall, so a second request on the same client is a 401 regardless of roles.
 */
class ClubCheckAccessTest extends WebTestCase
{
    private function persistRolelessUser(EntityManagerInterface $em, string $prefix): User
    {
        $user = new User($prefix . '-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        // No setRoles() call at all: roles stays [], as it did for every account registered
        // before ROLE_CLUB existed.
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * The 404 branch is the answer for an account with no club. A role check in front of it
     * made that branch unreachable for exactly those callers, and the client reads anything
     * other than 404 as "the server refused this account".
     */
    public function testAnAccountWithNoClubIsToldSoRatherThanRefused(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->persistRolelessUser($em, 'club-check-no-club');

        $client->loginUser($user, 'api');
        $client->request('GET', '/api/club/check');

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['exists']);
        $this->assertSame('club_not_found', $data['reason']);
    }

    /**
     * The guarantee in User::getRoles() has to survive the firewall, not just unit assertions:
     * a stored-roles-less account must still reach the ROLE_CLUB endpoints the app polls.
     * /api/club/all is gated on ROLE_CLUB and answers 200 with an empty list when there is no
     * club, so a 403 here is the regression and a 200 is the fix.
     */
    public function testARolelessAccountStillReachesARoleClubEndpoint(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->persistRolelessUser($em, 'club-check-role-guarantee');

        $client->loginUser($user, 'api');
        $client->request('GET', '/api/club/all');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame([], $data['clubs']);
    }

    /**
     * A stale X-Club-Id — the state a half-finished club creation leaves on the device — must
     * also be answered, not refused. ClubMismatchException is an AccessDeniedHttpException, so
     * without the catch this endpoint still produces a 403 the client cannot tell apart from a
     * dead account, after everything above was fixed.
     */
    public function testAnUnownedClubIdIsAnsweredRatherThanRefused(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->persistRolelessUser($em, 'club-check-stale-id');

        $client->loginUser($user, 'api');
        $client->request('GET', '/api/club/check', server: [
            'HTTP_X_CLUB_ID' => '01999999-9999-7999-8999-999999999999',
        ]);

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['exists']);
        $this->assertSame('club_not_owned', $data['reason']);
    }

    public function testCheckStillRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/club/check');

        $this->assertResponseStatusCodeSame(401);
    }
}
