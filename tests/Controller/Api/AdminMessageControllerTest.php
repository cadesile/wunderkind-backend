<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\AdminMessage;
use App\Entity\AudienceGroup;
use App\Entity\AudienceGroupMember;
use App\Entity\Club;
use App\Entity\User;
use App\Enum\AudienceCriteriaType;
use App\Enum\MessageDisplayType;
use App\Enum\MessagePriority;
use App\Enum\MessageTargetType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminMessageControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $currentUserId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->purgeMessagingTables();
    }

    /**
     * Broadcast messages reach every club, so a row left behind by an earlier test method
     * turns up in a later poll and breaks assertions that count what was delivered. The test
     * DB is shared and never rolled back between methods, so clear the messaging tables here.
     */
    private function purgeMessagingTables(): void
    {
        $this->em->getConnection()->executeStatement(
            'TRUNCATE message_delivery, admin_message_audience_group, admin_message,
                      audience_group_member, audience_group CASCADE',
        );
    }

    private function createClub(int $reputation = 0): Club
    {
        $user = new User('msg-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);

        $club = new Club('Messaging FC', $user);
        $club->setReputation($reputation);

        $this->em->persist($user);
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    /**
     * Selects the club that subsequent requests act as.
     *
     * Authentication is re-established per request by authenticatedRequest(); see the note
     * there for why. Persistence setup should happen before this is called, since each
     * request reboots the kernel and detaches previously managed entities.
     */
    private function login(Club $club): void
    {
        $this->currentUserId = $club->getUser()->getId();
    }

    /**
     * Issues one authenticated request, rebooting the kernel and logging in first.
     *
     * loginUser() authenticates exactly ONE request against this stateless JWT firewall — the
     * next request on the same client returns 401 and the client never recovers, and a second
     * loginUser() call does not help either. Since the test env has no JWT keys configured
     * (JWT_SECRET_KEY is empty in .env and .env.local is skipped under APP_ENV=test), minting
     * a real bearer token is not an option, so a fresh client per request is the only way to
     * exercise more than one authenticated call. Rebooting also swaps $this->em, which is why
     * only ids may be carried across a request boundary.
     */
    private function authenticatedRequest(string $method, string $uri, ?string $content = null): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->em->find(User::class, $this->currentUserId);
        $this->client->loginUser($user, 'api');

        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $content,
        );
    }

    private function createMessage(
        string $title = 'Release notes',
        MessageTargetType $target = MessageTargetType::BROADCAST,
        MessageDisplayType $display = MessageDisplayType::INBOX_ITEM,
        MessagePriority $priority = MessagePriority::STANDARD,
        bool $active = true,
    ): AdminMessage {
        $message = new AdminMessage();
        $message->setTitle($title);
        $message->setBodyHtml('<p>Hello</p>');
        $message->setTargetType($target);
        $message->setDisplayType($display);
        $message->setPriority($priority);
        $message->setIsActive($active);
        $message->setValidFrom(new \DateTimeImmutable('-1 hour'));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /** @return array<string, mixed> */
    private function poll(): array
    {
        $this->authenticatedRequest('GET', '/api/messages/pending');
        $this->assertResponseStatusCodeSame(200);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /** @return list<string> */
    private function polledTitles(): array
    {
        return array_column($this->poll()['messages'], 'title');
    }

    private function ack(AdminMessage $message, string $status = 'displayed'): void
    {
        $this->authenticatedRequest(
            'POST',
            '/api/messages/' . $message->getId()->toRfc4122() . '/ack',
            json_encode(['status' => $status]),
        );
    }

    private function deliveryCount(AdminMessage $message): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM message_delivery WHERE message_id = ?',
            [$message->getId()->toRfc4122()],
        );
    }

    public function testUnauthenticatedPollIsRejected(): void
    {
        $this->client->request('GET', '/api/messages/pending');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUnauthenticatedAckIsRejected(): void
    {
        $this->client->request('POST', '/api/messages/' . (new \Symfony\Component\Uid\UuidV7())->toRfc4122() . '/ack');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedUserWithoutAClubGets404(): void
    {
        $user = new User('msg-noclub-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->em->flush();

        $this->currentUserId = $user->getId();
        $this->authenticatedRequest('GET', '/api/messages/pending');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testBroadcastMessageIsReturnedWithTheContractedShape(): void
    {
        $this->login($this->createClub());
        $message = $this->createMessage('Season 2 Update');

        $data = $this->poll();

        $this->assertCount(1, $data['messages']);
        $payload = $data['messages'][0];

        // camelCase keys are a contract with the client's TS types.
        $this->assertSame(
            ['id', 'title', 'bodyHtml', 'priority', 'displayType', 'createdAt'],
            array_keys($payload),
        );
        $this->assertSame($message->getId()->toRfc4122(), $payload['id']);
        $this->assertSame('Season 2 Update', $payload['title']);
        $this->assertSame(2, $payload['priority'], 'priority must serialise as its int value');
        $this->assertSame('inbox_item', $payload['displayType']);
    }

    /** The core guarantee: a message is shown once. */
    public function testAcknowledgedMessageIsNotReturnedAgain(): void
    {
        $this->login($this->createClub());
        $message = $this->createMessage('One-shot');

        $this->assertSame(['One-shot'], $this->polledTitles());

        $this->ack($message);
        $this->assertResponseStatusCodeSame(200);
        $this->assertTrue(json_decode($this->client->getResponse()->getContent(), true)['success']);

        $this->assertSame([], $this->polledTitles(), 'An acknowledged message must not be re-delivered.');
    }

    public function testRepeatedAckIsIdempotentAndLeavesOneRow(): void
    {
        $this->login($this->createClub());
        $message = $this->createMessage();

        $this->ack($message, 'displayed');
        $this->assertResponseStatusCodeSame(200);

        $this->ack($message, 'dismissed');
        $this->assertResponseStatusCodeSame(200);

        $this->ack($message, 'dismissed');
        $this->assertResponseStatusCodeSame(200);

        $this->assertSame(1, $this->deliveryCount($message));
    }

    public function testAckRejectsPendingStatus(): void
    {
        $this->login($this->createClub());
        $message = $this->createMessage();

        $this->ack($message, 'pending');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->deliveryCount($message));
    }

    public function testAckOfAnUnknownMessageIs404NotAServerError(): void
    {
        $this->login($this->createClub());

        $this->authenticatedRequest(
            'POST',
            '/api/messages/' . (new \Symfony\Component\Uid\UuidV7())->toRfc4122() . '/ack',
            json_encode(['status' => 'displayed']),
        );
        $this->assertResponseStatusCodeSame(404);

        // A malformed uuid must not reach Doctrine's uuid converter and 500.
        $this->authenticatedRequest(
            'POST',
            '/api/messages/not-a-uuid/ack',
            json_encode(['status' => 'displayed']),
        );
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInactiveMessageIsNotDelivered(): void
    {
        $this->login($this->createClub());
        $this->createMessage('Draft', active: false);

        $this->assertSame([], $this->polledTitles());
    }

    public function testMessagesOutsideTheValidityWindowAreNotDelivered(): void
    {
        $this->login($this->createClub());

        $future = $this->createMessage('Not yet');
        $future->setValidFrom(new \DateTimeImmutable('+1 day'));

        $expired = $this->createMessage('Too late');
        $expired->setValidFrom(new \DateTimeImmutable('-2 days'));
        $expired->setValidUntil(new \DateTimeImmutable('-1 day'));

        $live = $this->createMessage('Live');
        $live->setValidUntil(new \DateTimeImmutable('+1 day'));

        $this->em->flush();

        $this->assertSame(['Live'], $this->polledTitles());
    }

    public function testDirectMessageReachesOnlyItsTargetClub(): void
    {
        $target = $this->createClub();
        $other  = $this->createClub();

        $message = $this->createMessage('Just for you', MessageTargetType::DIRECT_CLUB);
        $message->setTargetClub($target);
        $this->em->flush();

        $this->login($other);
        $this->assertSame([], $this->polledTitles());

        $this->login($target);
        $this->assertSame(['Just for you'], $this->polledTitles());
    }

    public function testDynamicGroupTargetsByReputation(): void
    {
        $whale  = $this->createClub(reputation: 5000);
        $minnow = $this->createClub(reputation: 10);

        $group = new AudienceGroup('Whales', 'whales-' . uniqid('', true));
        $group->setCriteriaType(AudienceCriteriaType::DYNAMIC);
        $group->setCriteriaPayload(['minReputation' => 1000]);
        $this->em->persist($group);

        $message = $this->createMessage('Whale news', MessageTargetType::GROUP_SEGMENTED);
        $message->addAudienceGroup($group);
        $this->em->flush();

        $this->login($minnow);
        $this->assertSame([], $this->polledTitles());

        $this->login($whale);
        $this->assertSame(['Whale news'], $this->polledTitles());
    }

    public function testManualGroupTargetsOnlyItsMembers(): void
    {
        $member    = $this->createClub();
        $nonMember = $this->createClub();

        $group = new AudienceGroup('Beta Testers', 'beta-' . uniqid('', true));
        $group->setCriteriaType(AudienceCriteriaType::MANUAL);
        $this->em->persist($group);
        $this->em->persist(new AudienceGroupMember($member, $group));

        $message = $this->createMessage('Beta build', MessageTargetType::GROUP_SEGMENTED);
        $message->addAudienceGroup($group);
        $this->em->flush();

        $this->login($nonMember);
        $this->assertSame([], $this->polledTitles());

        $this->login($member);
        $this->assertSame(['Beta build'], $this->polledTitles());
    }

    /**
     * The candidate query proves SOME group qualified, not which. A non-member must not be
     * let in by a dynamic group riding along on the same message.
     */
    public function testNonMemberIsNotAdmittedByASecondDynamicGroupOnTheSameMessage(): void
    {
        $nonMember = $this->createClub(reputation: 5);

        $manual = new AudienceGroup('Beta Testers', 'beta-mix-' . uniqid('', true));
        $manual->setCriteriaType(AudienceCriteriaType::MANUAL);

        $dynamic = new AudienceGroup('Whales', 'whales-mix-' . uniqid('', true));
        $dynamic->setCriteriaType(AudienceCriteriaType::DYNAMIC);
        $dynamic->setCriteriaPayload(['minReputation' => 1000]);

        $this->em->persist($manual);
        $this->em->persist($dynamic);

        $message = $this->createMessage('Mixed targeting', MessageTargetType::GROUP_SEGMENTED);
        $message->addAudienceGroup($manual);
        $message->addAudienceGroup($dynamic);
        $this->em->flush();

        $this->login($nonMember);
        $this->assertSame([], $this->polledTitles());
    }

    public function testAtMostOneBlockingModalIsDeliveredAndItLeads(): void
    {
        $this->login($this->createClub());

        $this->createMessage('blocking-low', display: MessageDisplayType::MODAL_BLOCKING, priority: MessagePriority::LOW);
        $this->createMessage('blocking-urgent', display: MessageDisplayType::MODAL_BLOCKING, priority: MessagePriority::URGENT);
        $this->createMessage('inbox-one', priority: MessagePriority::STANDARD);

        $titles = $this->polledTitles();

        $this->assertSame(['blocking-urgent', 'inbox-one'], $titles);
    }

    public function testHigherPriorityIsServedFirst(): void
    {
        $this->login($this->createClub());

        $this->createMessage('low', priority: MessagePriority::LOW);
        $this->createMessage('urgent', priority: MessagePriority::URGENT);
        $this->createMessage('standard', priority: MessagePriority::STANDARD);

        $this->assertSame(['urgent', 'standard', 'low'], $this->polledTitles());
    }

    public function testNonBlockingMessagesAreCappedAtFive(): void
    {
        $this->login($this->createClub());

        for ($i = 0; $i < 8; $i++) {
            $this->createMessage("bulk-{$i}");
        }

        $this->assertCount(5, $this->poll()['messages']);
    }

    public function testAcknowledgementIsScopedToTheAcknowledgingClub(): void
    {
        $first  = $this->createClub();
        $second = $this->createClub();

        $message = $this->createMessage('Shared broadcast');

        $this->login($first);
        $this->ack($message);
        $this->assertSame([], $this->polledTitles());

        $this->login($second);
        $this->assertSame(
            ['Shared broadcast'],
            $this->polledTitles(),
            "One user's acknowledgement must not suppress the message for another user.",
        );
    }

    /**
     * The reason deliveries key on User rather than Club.
     *
     * ClubRepository::findByUser() resolves only the most recently created club, so with
     * club-keyed deliveries a player starting a second club would be shown every active
     * announcement again.
     */
    public function testStartingANewClubDoesNotReplayAcknowledgedMessages(): void
    {
        $club    = $this->createClub();
        $user    = $club->getUser();
        $message = $this->createMessage('Seen once');

        $this->login($club);
        $this->ack($message, 'dismissed');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame([], $this->polledTitles());

        // Same person, brand-new club — which is what findByUser() will now resolve to.
        $newerClub = new Club('Second FC', $this->em->find(User::class, $user->getId()));
        $this->em->persist($newerClub);
        $this->em->flush();

        $this->login($newerClub);

        $this->assertSame(
            [],
            $this->polledTitles(),
            'A new club must not replay announcements the same user already dismissed.',
        );
        $this->assertSame(1, $this->deliveryCount($message), 'Still one delivery row for the user.');
    }

    public function testAcknowledgingWorksWithoutAClub(): void
    {
        // Deliveries key on User, so acking needs no club — a player who has just deleted or
        // replaced their club can still retire a message they have already seen.
        $user = new User('msg-ack-noclub-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->em->flush();

        $message = $this->createMessage('No club needed');

        $this->currentUserId = $user->getId();
        $this->authenticatedRequest(
            'POST',
            '/api/messages/' . $message->getId()->toRfc4122() . '/ack',
            json_encode(['status' => 'displayed']),
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(1, $this->deliveryCount($message));
    }

    /**
     * Guests are device-bound User rows under a synthetic email domain; nothing in the
     * messaging system inspects that domain or verification status.
     */
    public function testGuestAndRegisteredUsersAreServedIdentically(): void
    {
        $guest = new User('guest-' . uniqid('', true) . '@guest.buildmyclub.local');
        $guest->setPassword('x');
        $guest->setRoles([User::ROLE_CLUB]);
        $guest->setIsVerified(true);
        $guestClub = new Club('Guest FC', $guest);
        $this->em->persist($guest);
        $this->em->persist($guestClub);

        $registered     = $this->createClub();
        $registeredUser = $registered->getUser();
        $registeredUser->setIsVerified(true);

        $message = $this->createMessage('Everyone sees this');
        $this->em->flush();

        $this->login($guestClub);
        $this->assertSame(['Everyone sees this'], $this->polledTitles());

        $this->login($registered);
        $this->assertSame(['Everyone sees this'], $this->polledTitles());

        // And one acking does not retire it for the other.
        $this->login($guestClub);
        $this->ack($message, 'dismissed');
        $this->assertResponseStatusCodeSame(200);

        $this->login($guestClub);
        $this->assertSame([], $this->polledTitles());

        $this->login($registered);
        $this->assertSame(['Everyone sees this'], $this->polledTitles());
    }
}
