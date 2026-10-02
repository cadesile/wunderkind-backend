<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\SyncRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers GET /api/club/all — see docs/api/club-list.md. Unlike every other ClubController
 * action, this deliberately returns every club the authenticated account owns rather than
 * resolving to a single one via ClubResolver.
 */
class ClubControllerAllClubsTest extends WebTestCase
{
    public function testReturnsEveryClubOwnedByTheAuthenticatedAccountWithLastSyncSummary(): void
    {
        $client = static::createClient();
        $em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('all-clubs-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);

        $syncedClub = new Club('Synced FC', $user);
        $syncedClub->setCountry('EN');
        $syncedClub->setLastSyncedWeek(12);
        $syncedClub->setLastSyncedAt(new \DateTimeImmutable('2026-10-01T12:00:00+00:00'));
        $syncedClub->setTotalCareerEarnings(500_000);
        $em->persist($syncedClub);

        $unsyncedClub = new Club('Unsynced FC', $user);
        $em->persist($unsyncedClub);

        $em->flush();

        $syncRecord = new SyncRecord($syncedClub, 12, new \DateTimeImmutable('2026-10-01T12:00:00+00:00'), [
            'leaguePosition' => 4,
            'form'           => ['W', 'L', 'D'],
        ]);
        $em->persist($syncRecord);
        $em->flush();

        $client->loginUser($user, 'api');
        $client->request('GET', '/api/club/all');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(2, $data['clubs']);

        $byName = [];
        foreach ($data['clubs'] as $club) {
            $byName[$club['name']] = $club;
        }

        $synced = $byName['Synced FC'];
        $this->assertSame('EN', $synced['country']);
        $this->assertSame(500_000, $synced['totalCareerEarnings']);
        $this->assertNotNull($synced['lastSync']);
        $this->assertSame(12, $synced['lastSync']['weekNumber']);
        $this->assertSame(4, $synced['lastSync']['leaguePosition']);
        $this->assertSame(['W', 'L', 'D'], $synced['lastSync']['form']);

        $unsynced = $byName['Unsynced FC'];
        $this->assertNull($unsynced['lastSync']);

        $em->remove($syncRecord);
        $em->remove($syncedClub);
        $em->remove($unsyncedClub);
        $em->remove($user);
        $em->flush();
    }

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/club/all');

        $this->assertResponseStatusCodeSame(401);
    }
}
