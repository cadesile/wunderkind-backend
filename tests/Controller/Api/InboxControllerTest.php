<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\InboxMessage;
use App\Entity\User;
use App\Enum\MessageSenderType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class InboxControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $currentUserId = null;
    private mixed $currentClubId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createClub(string $name, int $balance = 100_000, int $reputation = 50): Club
    {
        $user = new User('inbox-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);

        $club = new Club($name, $user);
        $club->setBalance($balance);
        $club->setReputation($reputation);

        $this->em->persist($user);
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function login(Club $club): void
    {
        $this->currentUserId = $club->getUser()->getId();
        $this->currentClubId = $club->getId();
    }

    private function authenticatedRequest(string $method, string $uri, ?string $content = null): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->em->find(User::class, $this->currentUserId);
        $this->client->loginUser($user, 'api');

        $server = [
            'CONTENT_TYPE'   => 'application/json',
            'HTTP_X_CLUB_ID' => (string) $this->currentClubId,
        ];

        $this->client->request($method, $uri, server: $server, content: $content);
    }

    public function testListInboxRequiresAuth(): void
    {
        $this->client->request('GET', '/api/inbox');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListInboxReturnsTypeLinkAndRequiresAcceptAndExcludesDismissed(): void
    {
        $club = $this->createClub('Inbox United');
        $this->login($club);

        $msg1 = new InboxMessage(
            $club,
            MessageSenderType::COMPETITION,
            'Wunderkind Competitions',
            'You won the Test Cup!',
            'Congratulations on your victory.',
        );
        $msg1->setOfferData([
            'type'          => 'COMPETITION_WON',
            'competitionId' => '11111111-1111-1111-1111-111111111111',
            'effects'       => [['type' => 'ledger_delta', 'amountPence' => 50000]],
        ]);
        $msg1->setRelatedEntityType('active_competition');
        $msg1->setRelatedEntityId('11111111-1111-1111-1111-111111111111');

        $msg2 = new InboxMessage(
            $club,
            MessageSenderType::COMPETITION,
            'Wunderkind Competitions',
            'Round 1 drawn',
            'Check the bracket.',
        );
        $msg2->setOfferData([
            'type'          => 'ROUND_DRAWN',
            'competitionId' => '22222222-2222-2222-2222-222222222222',
            'effects'       => [],
        ]);
        $msg2->dismiss();

        $this->em->persist($msg1);
        $this->em->persist($msg2);
        $this->em->flush();

        $this->authenticatedRequest('GET', '/api/inbox');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $data['messages']);
        $item = $data['messages'][0];
        $this->assertSame('COMPETITION_WON', $item['type']);
        $this->assertTrue($item['requiresAccept']);
        $this->assertSame(['kind' => 'competition', 'id' => '11111111-1111-1111-1111-111111111111'], $item['link']);
    }

    public function testAcceptAppliesEffectsAndRepeatReturns409AlreadyProcessed(): void
    {
        $club = $this->createClub('Accept Rovers', 100_000, 50);
        $this->login($club);

        $msg = new InboxMessage(
            $club,
            MessageSenderType::COMPETITION,
            'Wunderkind Competitions',
            'Prize money',
            'Claim your reward.',
        );
        $msg->setOfferData([
            'type'          => 'COMPETITION_WON',
            'competitionId' => '11111111-1111-1111-1111-111111111111',
            'effects'       => [
                ['type' => 'ledger_delta', 'amountPence' => 75_000],
                ['type' => 'reputation_delta', 'delta' => 10],
            ],
        ]);
        $this->em->persist($msg);
        $this->em->flush();
        $msgId = (string) $msg->getId();

        $this->authenticatedRequest('POST', '/api/inbox/' . $msgId . '/accept');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('accepted', $data['status']);
        $this->assertCount(2, $data['appliedEffects']);
        $this->assertSame(175_000, $data['club']['balance']);
        $this->assertSame(60, $data['club']['reputation']);

        // Second accept should return 409 already_processed
        $this->authenticatedRequest('POST', '/api/inbox/' . $msgId . '/accept');
        $this->assertResponseStatusCodeSame(409);
        $err = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('already_processed', $err['error']);
    }

    public function testDismissHidesMessageFromInbox(): void
    {
        $club = $this->createClub('Dismiss Athletic');
        $this->login($club);

        $msg = new InboxMessage(
            $club,
            MessageSenderType::COMPETITION,
            'Wunderkind Competitions',
            'Notification',
            'Tournament locked.',
        );
        $msg->setOfferData([
            'type'          => 'COMPETITION_LOCKED',
            'competitionId' => '11111111-1111-1111-1111-111111111111',
            'effects'       => [],
        ]);
        $this->em->persist($msg);
        $this->em->flush();
        $msgId = (string) $msg->getId();

        $this->authenticatedRequest('POST', '/api/inbox/' . $msgId . '/dismiss');
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('dismissed', $data['status']);

        $this->authenticatedRequest('GET', '/api/inbox');
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(0, $list['messages']);
    }
}
