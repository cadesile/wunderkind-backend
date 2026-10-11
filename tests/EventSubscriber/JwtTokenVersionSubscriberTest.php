<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Admin;
use App\Entity\User;
use App\EventSubscriber\JwtTokenVersionSubscriber;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * This subscriber sits in the authentication path of every API request, so the
 * cases that matter most are the ones where it must NOT reject: a bug that
 * invalidates tokens wholesale would log out the entire user base at once.
 */
class JwtTokenVersionSubscriberTest extends TestCase
{
    /** @param int|false $storedVersion false = no such user */
    private function subscriber(int|false $storedVersion, string $idClaim = 'username'): JwtTokenVersionSubscriber
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($storedVersion);

        $jwtManager = $this->createStub(JWTTokenManagerInterface::class);
        $jwtManager->method('getUserIdClaim')->willReturn($idClaim);

        return new JwtTokenVersionSubscriber($connection, $jwtManager);
    }

    private function decode(JwtTokenVersionSubscriber $s, array $payload): JWTDecodedEvent
    {
        $event = new JWTDecodedEvent($payload);
        $s->onJwtDecoded($event);

        return $event;
    }

    // ── minting ──────────────────────────────────────────────────────────

    public function testTheUsersCurrentVersionIsStampedIntoANewToken(): void
    {
        $user = new User('owner@test.local');
        $user->bumpTokenVersion();
        $user->bumpTokenVersion();

        $event = new JWTCreatedEvent(['username' => 'owner@test.local'], $user);
        $this->subscriber(2)->onJwtCreated($event);

        $this->assertSame(2, $event->getData()['tv']);
    }

    public function testAFreshAccountStampsVersionZero(): void
    {
        $event = new JWTCreatedEvent([], new User('new@test.local'));
        $this->subscriber(0)->onJwtCreated($event);

        $this->assertSame(0, $event->getData()['tv']);
    }

    public function testANonGameAccountIsLeftAlone(): void
    {
        $event = new JWTCreatedEvent(['username' => 'ops@test.local'], new Admin('ops@test.local'));
        $this->subscriber(0)->onJwtCreated($event);

        $this->assertArrayNotHasKey('tv', $event->getData());
    }

    // ── validating ───────────────────────────────────────────────────────

    public function testAMatchingVersionIsAccepted(): void
    {
        $event = $this->decode($this->subscriber(3), ['username' => 'a@test.local', 'tv' => 3]);

        $this->assertTrue($event->isValid());
    }

    public function testASupersededVersionIsRejected(): void
    {
        $event = $this->decode($this->subscriber(4), ['username' => 'a@test.local', 'tv' => 3]);

        $this->assertFalse($event->isValid());
    }

    /**
     * The deploy-safety case. Every token already in the wild predates the `tv`
     * claim; if an absent claim did not read as 0 this would sign out every
     * user the moment it shipped.
     */
    public function testATokenWithNoVersionClaimIsAcceptedForAnUntouchedAccount(): void
    {
        $event = $this->decode($this->subscriber(0), ['username' => 'a@test.local']);

        $this->assertTrue($event->isValid());
    }

    /** ...but the same old token must still die once that account is killed. */
    public function testATokenWithNoVersionClaimIsRejectedOnceTheAccountIsKilled(): void
    {
        $event = $this->decode($this->subscriber(1), ['username' => 'a@test.local']);

        $this->assertFalse($event->isValid());
    }

    // ── failing open ─────────────────────────────────────────────────────

    public function testAnUnknownAccountIsNotRejectedHere(): void
    {
        // The user provider issues that 401; pre-empting it would mean this
        // subscriber decides authentication outcomes it has no business deciding.
        $event = $this->decode($this->subscriber(false), ['username' => 'ghost@test.local', 'tv' => 9]);

        $this->assertTrue($event->isValid());
    }

    public function testAPayloadMissingTheIdentityClaimIsNotRejected(): void
    {
        $event = $this->decode($this->subscriber(5), ['tv' => 1]);

        $this->assertTrue($event->isValid());
    }

    public function testANonStringIdentityClaimIsNotRejected(): void
    {
        $event = $this->decode($this->subscriber(5), ['username' => ['array'], 'tv' => 1]);

        $this->assertTrue($event->isValid());
    }

    /** The claim name is configurable (lexik user_id_claim), so don't hardcode it. */
    public function testACustomIdentityClaimNameIsHonoured(): void
    {
        $event = $this->decode($this->subscriber(7, 'email'), ['email' => 'a@test.local', 'tv' => 7]);
        $this->assertTrue($event->isValid());

        $event = $this->decode($this->subscriber(7, 'email'), ['email' => 'a@test.local', 'tv' => 6]);
        $this->assertFalse($event->isValid());
    }

    public function testAStringVersionClaimStillCompares(): void
    {
        // JSON round-trips can hand back a numeric string; a loose mismatch here
        // would be an unexplained logout.
        $event = $this->decode($this->subscriber(2), ['username' => 'a@test.local', 'tv' => '2']);

        $this->assertTrue($event->isValid());
    }
}
