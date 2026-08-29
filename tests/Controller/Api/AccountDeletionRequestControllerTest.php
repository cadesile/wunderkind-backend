<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\DeletionRequest;
use App\Entity\User;
use App\Enum\DeletionRequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AccountDeletionRequestControllerTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    /**
     * A client on an IP address no other test shares.
     *
     * The endpoint rate-limits by counting persisted DeletionRequest rows for the
     * caller's IP over a rolling 15-minute window (AccountDeletionRequestController
     * ::RATE_LIMIT). Those rows are the audit trail — they are never cleaned up — so
     * on the default 127.0.0.1 the counter is shared by every test in this class AND
     * by every previous run: four invalid-credential rows per run means the third
     * consecutive run inside the window trips the limit and the endpoint starts
     * answering the tests themselves with 429. A per-test IP gives each one its own
     * counter, so the limiter can never be tripped by anything but that test.
     */
    private function newClient(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient([], ['REMOTE_ADDR' => '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254)]);
    }

    private function post(KernelBrowser $client, array $fields): array
    {
        $client->request('POST', '/api/account/delete-request', $fields);

        return json_decode($client->getResponse()->getContent(), true) ?? [];
    }

    /** Registered user with a real hashed password and one club. */
    private function makeUser(string $email): User
    {
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User($email);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->persist(new Club('Deletion Test FC', $user));
        $em->flush();

        return $user;
    }

    private function latestRequestFor(string $email): ?DeletionRequest
    {
        return self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(DeletionRequest::class)
            // requested_at is TIMESTAMP(0); same-second rows tie, and UuidV7 is
            // time-ordered, so it breaks the tie deterministically.
            ->findOneBy(['email' => $email], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }

    // ── Happy path ───────────────────────────────────────────────────────

    public function testValidCredentialsDeleteTheAccountAndItsClubs(): void
    {
        $client = $this->newClient();
        $email  = 'del-ok-' . uniqid() . '@example.com';
        $user   = $this->makeUser($email);
        $userId = $user->getId();

        $data = $this->post($client, ['email' => $email, 'password' => self::PASSWORD, 'confirm' => 'DELETE']);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['success']);
        self::assertSame(1, $data['clubsDeleted']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(User::class, $userId), 'the user row should be gone');

        $record = $this->latestRequestFor($email);
        self::assertNotNull($record, 'an audit row is the whole point of this flow');
        self::assertSame(DeletionRequestStatus::COMPLETED, $record->getStatus());
        self::assertSame(1, $record->getClubsDeleted());
        self::assertNotNull($record->getCompletedAt());
    }

    public function testEmailIsMatchedCaseInsensitively(): void
    {
        $client = $this->newClient();
        $email  = 'del-case-' . uniqid() . '@example.com';
        $this->makeUser($email);

        $data = $this->post($client, [
            'email'    => strtoupper($email),
            'password' => self::PASSWORD,
            'confirm'  => 'DELETE',
        ]);

        self::assertTrue($data['success'], 'a shouted email address is still the same account');
    }

    // ── Guest accounts ───────────────────────────────────────────────────

    /**
     * Device-bound guests have a synthetic address and no password the user ever
     * chose, so this form can never work for them. They must be told to use the
     * in-app button rather than left retrying credentials that do not exist.
     */
    public function testGuestAccountIsRejectedWithActionableGuidance(): void
    {
        $client = $this->newClient();
        $email  = 'device-abc' . User::GUEST_EMAIL_DOMAIN;

        $data = $this->post($client, ['email' => $email, 'password' => 'anything', 'confirm' => 'DELETE']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('guest_account', $data['code']);
        self::assertStringContainsStringIgnoringCase('app', $data['message']);

        $record = $this->latestRequestFor($email);
        self::assertNotNull($record);
        self::assertSame(DeletionRequestStatus::REJECTED_GUEST, $record->getStatus());
    }

    public function testGuestCheckHappensBeforeAnyCredentialLookup(): void
    {
        $client = $this->newClient();

        // No such account exists at all; the guest domain alone must decide.
        $data = $this->post($client, [
            'email'    => 'never-registered' . User::GUEST_EMAIL_DOMAIN,
            'password' => '',
            'confirm'  => 'DELETE',
        ]);

        self::assertSame('guest_account', $data['code']);
    }

    // ── Credential failures ──────────────────────────────────────────────

    /**
     * An unknown address and a wrong password must be indistinguishable, or the
     * form becomes an oracle for "is this person registered?".
     */
    public function testUnknownEmailAndWrongPasswordAreIndistinguishable(): void
    {
        $client = $this->newClient();
        $known  = 'del-wrong-' . uniqid() . '@example.com';
        $this->makeUser($known);

        $wrongPassword = $this->post($client, ['email' => $known, 'password' => 'not-the-password', 'confirm' => 'DELETE']);
        $wrongStatus   = $client->getResponse()->getStatusCode();

        $unknownEmail  = $this->post($client, ['email' => 'nobody-' . uniqid() . '@example.com', 'password' => 'whatever', 'confirm' => 'DELETE']);
        $unknownStatus = $client->getResponse()->getStatusCode();

        self::assertSame($wrongStatus, $unknownStatus, 'status codes must not differ');
        self::assertSame($wrongPassword['code'], $unknownEmail['code'], 'error codes must not differ');
        self::assertSame($wrongPassword['message'], $unknownEmail['message'], 'messages must not differ');
        self::assertSame(401, $wrongStatus);
    }

    public function testWrongPasswordLeavesTheAccountIntact(): void
    {
        $client = $this->newClient();
        $email  = 'del-intact-' . uniqid() . '@example.com';
        $userId = $this->makeUser($email)->getId();

        $this->post($client, ['email' => $email, 'password' => 'nope', 'confirm' => 'DELETE']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(User::class, $userId), 'a failed attempt must not delete anything');

        $record = $this->latestRequestFor($email);
        self::assertSame(DeletionRequestStatus::REJECTED_INVALID_CREDENTIALS, $record->getStatus());
    }

    // ── Input gates ──────────────────────────────────────────────────────

    /** The typed-DELETE gate is enforced server-side; a client-only check is theatre. */
    public function testMissingConfirmationIsRejectedBeforeCredentialsAreChecked(): void
    {
        $client = $this->newClient();
        $email  = 'del-noconfirm-' . uniqid() . '@example.com';
        $userId = $this->makeUser($email)->getId();

        $data = $this->post($client, ['email' => $email, 'password' => self::PASSWORD, 'confirm' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('not_confirmed', $data['code']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(User::class, $userId));
    }

    public function testConfirmationIsCaseInsensitive(): void
    {
        $client = $this->newClient();
        $email  = 'del-lower-' . uniqid() . '@example.com';
        $this->makeUser($email);

        $data = $this->post($client, ['email' => $email, 'password' => self::PASSWORD, 'confirm' => 'delete']);

        self::assertTrue($data['success']);
    }

    public function testMalformedEmailIsRejected(): void
    {
        $client = $this->newClient();

        $data = $this->post($client, ['email' => 'not-an-email', 'password' => 'x', 'confirm' => 'DELETE']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_email', $data['code']);
    }

    public function testEndpointIsPubliclyReachableWithoutAToken(): void
    {
        $client = $this->newClient();
        $client->request('POST', '/api/account/delete-request', ['email' => 'x@y.z', 'password' => 'p', 'confirm' => 'DELETE']);

        // Anything but 401-from-the-firewall proves the route is public; the
        // JWT-only /api/account/delete must keep returning 401 (see below).
        self::assertNotSame(
            'JWT Token not found',
            json_decode($client->getResponse()->getContent(), true)['message'] ?? null
        );
    }

    /** The narrower public rule must not have opened up the JWT-only endpoint. */
    public function testTheJwtOnlyDeleteEndpointIsStillProtected(): void
    {
        $client = $this->newClient();
        $client->request('POST', '/api/account/delete');

        self::assertResponseStatusCodeSame(401);
    }
}
