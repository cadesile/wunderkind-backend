<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Service\SessionRevocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Both halves of revocation against a real database — the refresh-token delete
 * is raw SQL against a table owned by the bundle, so a schema or column-name
 * assumption breaking is exactly the kind of thing a mock would hide.
 */
class SessionRevocationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    private function service(): SessionRevocationService
    {
        return self::getContainer()->get(SessionRevocationService::class);
    }

    private function user(): User
    {
        $user = new User(bin2hex(random_bytes(8)) . '@revoke.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->cleanup[] = $user;
        $this->em->flush();

        return $user;
    }

    private function refreshToken(User $user, string $validity): RefreshToken
    {
        $token = new RefreshToken();
        $token->setRefreshToken(bin2hex(random_bytes(16)));
        $token->setUsername($user->getUserIdentifier());
        $token->setValid(new \DateTime($validity));

        $this->em->persist($token);
        $this->cleanup[] = $token;
        $this->em->flush();

        return $token;
    }

    public function testRevokingBumpsTheTokenVersionSoIssuedAccessTokensStopMatching(): void
    {
        $user = $this->user();
        $this->assertSame(0, $user->getTokenVersion());

        $this->service()->revokeAllSessions($user);

        $this->assertSame(1, $user->getTokenVersion());
        $this->em->refresh($user);
        $this->assertSame(1, $user->getTokenVersion(), 'The bump must be flushed, not just in memory.');
    }

    public function testRevokingDeletesEveryRefreshTokenForThatAccount(): void
    {
        $user = $this->user();
        $this->refreshToken($user, '+30 days');
        $this->refreshToken($user, '+10 days');
        $this->refreshToken($user, '-1 day');  // already expired, still cleared

        $deleted = $this->service()->revokeAllSessions($user);

        $this->assertSame(3, $deleted);
        $this->assertSame(0, $this->service()->countActiveSessions($user));
    }

    public function testAnotherUsersTokensAreUntouched(): void
    {
        $target   = $this->user();
        $bystander = $this->user();
        $this->refreshToken($target, '+30 days');
        $this->refreshToken($bystander, '+30 days');

        $this->service()->revokeAllSessions($target);

        $this->assertSame(1, $this->service()->countActiveSessions($bystander));
        $this->assertSame(0, $bystander->getTokenVersion(), 'Only the targeted account is versioned forward.');
    }

    public function testCountActiveSessionsIgnoresExpiredTokens(): void
    {
        $user = $this->user();
        $this->refreshToken($user, '+30 days');
        $this->refreshToken($user, '-1 hour');

        $this->assertSame(1, $this->service()->countActiveSessions($user));
    }

    public function testRevokingAnAccountWithNoSessionsIsSafeAndStillBumps(): void
    {
        $user = $this->user();

        $this->assertSame(0, $this->service()->revokeAllSessions($user));
        $this->assertSame(1, $user->getTokenVersion());
    }

    public function testRepeatedRevocationKeepsMovingTheVersionForward(): void
    {
        $user = $this->user();
        $this->service()->revokeAllSessions($user);
        $this->service()->revokeAllSessions($user);

        $this->assertSame(2, $user->getTokenVersion());
    }
}
