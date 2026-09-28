<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\League;
use App\Entity\SeasonRecord;
use App\Entity\SyncRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers ClubCrudController::detail()'s custom club_profile.html.twig render — same
 * pattern as ActiveCompetitionCrudPageTest (detail() overridden, so the standard
 * EasyAdmin field-rendering path is bypassed entirely and only a real request through
 * it catches template errors; see this repo's CLAUDE.md note on the `ea` Twig context
 * only being populated on the real EasyAdmin route).
 *
 * Specifically exercises the sections added for chairman kit/badge identity, concluded
 * season history, and the richer sync-payload sections (ledger, promises, fixtures,
 * toxic relationships, excursions) pulled from real payload shapes — none of these were
 * previously rendered anywhere on this page, so a shape mismatch (e.g. a payload key
 * renamed client-side) would previously go unnoticed until an admin hit a 500 in
 * production.
 */
class ClubCrudPageTest extends WebTestCase
{
    private function loginAsAdmin(KernelBrowser $client, EntityManagerInterface $em): void
    {
        $admin = new Admin('club-crud-page-test-admin-' . uniqid('', true) . '@example.com');
        $admin->setPassword('not-used-for-login-here');
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin, 'admin');
    }

    private function createClub(EntityManagerInterface $em): Club
    {
        $user = new User('club-crud-page-test-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $em->persist($user);

        $club = new Club('Render Test FC', $user);
        $em->persist($club);

        return $club;
    }

    public function testDetailPageRendersWithNoDataPopulated(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loginAsAdmin($client, $em);

        $club = $this->createClub($em);
        $em->flush();

        $client->request('GET', '/admin/club/' . $club->getId());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        // Nothing populated — every conditional new section must stay hidden, not error.
        $this->assertStringNotContainsString('Kit &amp; Badge Identity', $html);
        $this->assertStringNotContainsString('Season History', $html);
        $this->assertStringNotContainsString('Squad Culture', $html);
        $this->assertStringNotContainsString('Financial Activity', $html);
        $this->assertStringNotContainsString('Recent Fixtures', $html);
    }

    public function testDetailPageRendersKitBadgeSeasonHistoryAndRichSyncSections(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loginAsAdmin($client, $em);

        $club = $this->createClub($em);
        $club->setHomeKitConfig(['kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee', 'shorts' => 'black', 'socks' => 'primary']);
        $club->setAwayKitConfig(['kit' => 'hoops', 'primary' => '#1f4fb8', 'secondary' => '#f4f3ee', 'shorts' => 'white', 'socks' => 'secondary']);
        $club->setBadgeConfig(['badgeShape' => 'shield', 'badgePattern' => 'plain', 'badgeCentre' => 'initials', 'initials' => 'TSD', 'badgeFill' => '#c8202f', 'badgeTrim' => '#f4f3ee', 'badgeSymbol' => '#f2c230']);

        $league = $em->getRepository(League::class)->findOneBy(['country' => 'EN', 'tier' => 5])
            ?? new League('EN', 5, 'Test League Tier 5');
        $em->persist($league);
        $seasonRecord = new SeasonRecord($club, $league, 1, 2, 28, 20, 5, 3, 60, 25, 65, true, false);
        $em->persist($seasonRecord);

        $payload = [
            'squadSize' => 20, 'staffCount' => 13, 'earningsDelta' => -2383628, 'reputationDelta' => 1,
            'leaguePosition' => 9, 'squadAvgOvr' => 4, 'form' => ['W', 'L', 'L', 'L'],
            'seasonRecord' => ['wins' => 1, 'draws' => 0, 'losses' => 3, 'goalsFor' => 5, 'goalsAgainst' => 6, 'points' => 3],
            'attendance' => ['fanCount' => 36500, 'fanSentiment' => 58, 'fanMorale' => 54, 'weeklyAttendance' => 1193],
            'ledger' => [
                ['category' => 'upkeep', 'amount' => -1413100, 'description' => 'Scouting mission'],
                ['category' => 'investor_income', 'amount' => 140000000, 'description' => "The Classic Centre — season 1 payment (5% equity)"],
            ],
            'fixtures' => [[
                'fixtureId' => 'fx1', 'season' => 1, 'weekNumber' => 13,
                'homeClubId' => (string) $club->getId(), 'homeClubName' => 'Render Test FC',
                'awayClubId' => 'other-club-id', 'awayClubName' => 'Opponent FC',
                'homeGoals' => 2, 'awayGoals' => 1, 'result' => 'HOME_WIN',
                'scorers' => [], 'assists' => [], 'cards' => [], 'homePlayerRatings' => [], 'awayPlayerRatings' => [],
                'playedAt' => '2026-09-28T11:05:14.033Z', 'stadiumName' => 'Test Stadium',
            ]],
            'relationships' => [
                ['kind' => 'player_player', 'playerId' => 'p1', 'playerName' => 'Player A', 'otherId' => 'p2', 'otherName' => 'Player B', 'bondValue' => -9, 'lastInteractionWeek' => 11],
                ['kind' => 'player_player', 'playerId' => 'p3', 'playerName' => 'Player C', 'otherId' => 'p4', 'otherName' => 'Player D', 'bondValue' => 15, 'lastInteractionWeek' => 5],
            ],
            'promises' => [[
                'id' => 'promise-1', 'type' => 'investment_contract',
                'partyA' => ['type' => 'investor', 'id' => 'inv1', 'name' => "Walt's Fencing"],
                'offer' => ['type' => 'seasonal_payment', 'amountPence' => 6700000, 'repeatSeasons' => 3],
                'condition' => ['type' => 'league_position', 'target' => 5],
                'status' => 'active',
            ]],
            'excursions' => [[
                'id' => 'ex1', 'slug' => 'escape-room-challenge', 'status' => 'resolved',
                'outcome' => ['frictionCount' => 3, 'summary' => 'Escape Room was soured by 3 fallouts.'],
            ]],
        ];

        $sync = new SyncRecord($club, 1, new \DateTimeImmutable(), $payload);
        $em->persist($sync);
        $em->flush();

        $client->request('GET', '/admin/club/' . $club->getId());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('Kit &amp; Badge Identity', $html);
        $this->assertStringContainsString('Season History', $html);
        $this->assertStringContainsString('Promoted', $html);
        $this->assertStringContainsString('Fan Sentiment', $html);
        $this->assertStringContainsString('Ledger (Latest Sync)', $html);
        $this->assertStringContainsString('Investor &amp; Sponsor Contracts', $html);
        $this->assertStringContainsString("Walt&#039;s Fencing", $html);
        $this->assertStringContainsString('Recent Fixtures', $html);
        $this->assertStringContainsString('Opponent FC', $html);
        $this->assertStringContainsString('Toxic Pairings', $html);
        $this->assertStringContainsString('Player A', $html);
        // The positive bond must NOT be listed as toxic.
        $this->assertStringNotContainsString('Player C', $html);
        $this->assertStringContainsString('Excursions', $html);
        $this->assertStringContainsString('3 fallouts', $html);
    }
}
