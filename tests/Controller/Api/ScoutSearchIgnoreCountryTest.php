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

/**
 * Covers the new ?ignore_country= param on GET /api/scout/search — separate file from any
 * pre-existing scout-search coverage so it's easy to find alongside the feature that added it.
 */
class ScoutSearchIgnoreCountryTest extends WebTestCase
{
    private function makePlayer(EntityManagerInterface $em, string $nationality, int $ability = 50): Player
    {
        $player = new Player('Test', 'Player', nationality: $nationality);
        $player->setPosition(PlayerPosition::MIDFIELDER);
        $player->setStatus(PlayerStatus::ACTIVE);
        $player->setRecruitmentSource(RecruitmentSource::YOUTH_REQUEST);
        $player->setDateOfBirth(new \DateTimeImmutable('-20 years'));
        $player->setCurrentAbility($ability);
        $player->setPotential(70);
        $em->persist($player);

        return $player;
    }

    private function loginNewClub(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('scout-ignore-country-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user, 'api');

        return $client;
    }

    public function testIgnoreCountryExcludesThatNationality(): void
    {
        $client = $this->loginNewClub();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->makePlayer($em, 'English');
        $this->makePlayer($em, 'Spanish');
        $this->makePlayer($em, 'French');
        $em->flush();

        $client->request('GET', '/api/scout/search?rep=local&amount=50&ignore_country=EN');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('EN', $data['ignoreCountry']);
        foreach ($data['players'] as $p) {
            $this->assertNotSame('English', $p['nationality']);
        }
    }

    public function testIgnoreCountryIsNullWhenNotPassed(): void
    {
        $client = $this->loginNewClub();

        $client->request('GET', '/api/scout/search?rep=local&amount=5');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($data['ignoreCountry']);
    }

    public function testUnknownIgnoreCountryIsRejected(): void
    {
        $client = $this->loginNewClub();

        $client->request('GET', '/api/scout/search?rep=local&ignore_country=XX');

        $this->assertResponseStatusCodeSame(422);
    }

    public function testIgnoreCountryComposesWithNationalityFilter(): void
    {
        $client = $this->loginNewClub();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->makePlayer($em, 'Spanish');
        $em->flush();

        // nationality=Spanish (include only) + ignore_country=EN (exclude English) —
        // not contradictory since Spanish != English, should still return the Spanish player.
        $client->request('GET', '/api/scout/search?rep=local&amount=50&nationality=Spanish&ignore_country=EN');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        foreach ($data['players'] as $p) {
            $this->assertSame('Spanish', $p['nationality']);
        }
    }
}
