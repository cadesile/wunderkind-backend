<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Player;
use App\Entity\User;
use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlayerControllerTest extends WebTestCase
{
    private function makePlayer(EntityManagerInterface $em, string $nationality): Player
    {
        $player = new Player('Test', 'Player', nationality: $nationality);
        $player->setPosition(PlayerPosition::MIDFIELDER);
        $player->setStatus(PlayerStatus::ACTIVE);
        $player->setRecruitmentSource(RecruitmentSource::YOUTH_REQUEST);
        $player->setDateOfBirth(new \DateTimeImmutable('-20 years'));
        $player->setCurrentAbility(50);
        $player->setPotential(70);
        $em->persist($player);

        return $player;
    }

    private function login(): User
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('player-foreign-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user, 'api');

        return $user;
    }

    public function testForeignExcludesTheGivenNationality(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->makePlayer($em, 'English');
        $this->makePlayer($em, 'Spanish');
        $this->makePlayer($em, 'French');
        $em->flush();

        $user = new User('player-foreign-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user, 'api');

        $client->request('GET', '/api/players/foreign?country=EN&amount=50');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('EN', $data['country']);
        foreach ($data['players'] as $p) {
            $this->assertNotSame('English', $p['nationality']);
        }
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/players/foreign?country=EN');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMissingCountryIsRejected(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('player-foreign-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user, 'api');

        $client->request('GET', '/api/players/foreign');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUnknownCountryCodeIsRejected(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('player-foreign-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user, 'api');

        $client->request('GET', '/api/players/foreign?country=XX');

        $this->assertResponseStatusCodeSame(422);
    }

    public function testAmountIsClampedToMax(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('player-foreign-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user, 'api');

        $client->request('GET', '/api/players/foreign?country=EN&amount=99999');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertLessThanOrEqual(200, $data['amount']);
    }
}
