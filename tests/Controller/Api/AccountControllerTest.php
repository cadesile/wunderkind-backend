<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\DeletionRequest;
use App\Entity\User;
use App\Enum\DeletionRequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AccountControllerTest extends WebTestCase
{
    public function testAuthenticatedDeleteRemovesAccountAndReturnsSuccess(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $email = 'acct-del-' . uniqid() . '@example.com';
        $user = new User($email);
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->persist(new Club('Endpoint FC', $user));
        $em->flush();
        $userId = $user->getId();

        $client->loginUser($user, 'api');
        $client->request('POST', '/api/account/delete');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        $em->clear();
        $this->assertNull($em->find(User::class, $userId), 'user should be deleted');

        // Same audit trail the web deletion form writes to — one row, marked completed,
        // with the club count captured before the club itself was gone.
        $record = $em->getRepository(DeletionRequest::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($record, 'in-app deletion should write a DeletionRequest audit row');
        $this->assertSame(DeletionRequestStatus::COMPLETED, $record->getStatus());
        $this->assertSame(1, $record->getClubsDeleted());
    }

    public function testUnauthenticatedDeleteIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/account/delete');
        $this->assertResponseStatusCodeSame(401);
    }
}
