<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AccountControllerTest extends WebTestCase
{
    public function testAuthenticatedDeleteRemovesAccountAndReturnsSuccess(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('acct-del-' . uniqid() . '@example.com');
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
    }

    public function testUnauthenticatedDeleteIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/account/delete');
        $this->assertResponseStatusCodeSame(401);
    }
}
