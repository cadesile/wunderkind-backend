<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\League;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The rebuilt League overview renders what the page's JavaScript needs to find.
 *
 * Almost everything on this screen is server-computed markup the script then wires up — the
 * tier rows, the country × tier picker, the per-league drift marks. A template that stops
 * emitting one of those looks fine until somebody opens it, so the hooks are asserted here
 * rather than left to a click-through.
 */
class LeagueOverviewPageTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'league-overview-test@example.com';
    private const COUNTRY     = 'ZL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purge();
        $this->loginAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        self::getContainer()->get(Connection::class)
            ->executeStatement('DELETE FROM league WHERE country = :c', ['c' => self::COUNTRY]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function loginAsAdmin(): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $this->client->loginUser($admin, 'admin');
    }

    private function seedLeague(int $tier, ?int $tvDeal = null): League
    {
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $league = new League(self::COUNTRY, $tier, 'Test Tier ' . $tier);
        $league->setTvDeal($tvDeal);

        $em->persist($league);
        $em->flush();

        return $league;
    }

    private function open(): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/admin', ['routeName' => 'admin_leagues_overview']);
        $this->assertResponseIsSuccessful();

        return $crawler;
    }

    public function testTheStripAndTheEightTierRowsRender(): void
    {
        $this->seedLeague(1);
        $crawler = $this->open();

        $this->assertSelectorTextContains('.bmc-title', 'LEAGUE OVERVIEW');
        self::assertCount(8, $crawler->filter('.bmc-tiergrid__row'), 'one row per tier, always');

        // Tier 1 cannot be promoted into, so it must not offer a promotion field.
        self::assertCount(0, $crawler->filter('input[name="tiers[1][promotionSpots]"]'));
        self::assertCount(1, $crawler->filter('input[name="tiers[2][promotionSpots]"]'));
    }

    public function testTheTrophyFieldsThePostExpectsArePresent(): void
    {
        $this->seedLeague(1);
        $crawler = $this->open();

        // The editor writes these; the form posts them. bulkEditTierDefaults() reads exactly
        // these names, so a rename here silently stops trophies saving.
        foreach (['trophyStyle', 'trophyFinish', 'trophyRibbons', 'trophyRibbonPrimary', 'trophyRibbonSecondary'] as $field) {
            self::assertCount(1, $crawler->filter(\sprintf('input[name="tiers[3][%s]"]', $field)), $field);
        }
    }

    public function testEachLeagueGetsACellAndAPyramidRow(): void
    {
        $a = $this->seedLeague(1);
        $b = $this->seedLeague(2);
        $crawler = $this->open();

        foreach ([$a, $b] as $league) {
            $id = (string) $league->getId();
            self::assertCount(1, $crawler->filter(\sprintf('input[name="leagueIds[]"][value="%s"]', $id)), 'picker cell');
            self::assertCount(1, $crawler->filter(\sprintf('.bmc-leaguerow[data-league="%s"]', $id)), 'pyramid row');
        }
    }

    /**
     * A league that has drifted is marked, and one that matches is not.
     *
     * The mark comes from LeagueTierDefaultDiff via the controller, so this is the end of that
     * wire: if the map stops reaching the template, every cell silently reads as matching.
     */
    public function testDriftIsMarkedOnThePicker(): void
    {
        // A FRESH LEAGUE DOES NOT AUTOMATICALLY MATCH ITS TIER, which is what the first
        // version of this assumed. getLeagueTierDefaultsForTier() falls back to
        // promotionSpots => 2 for an unsaved tier, and a new League carries none — so the
        // matching league has to be built from the defaults rather than left at its own.
        $defaults = self::getContainer()->get(\App\Repository\GameConfigRepository::class)
            ->getConfig()->getLeagueTierDefaultsForTier(5);

        $drifted = $this->seedLeague(4, 123400);

        $matches = $this->seedLeague(5);
        $matches->setPromotionSpots($defaults['promotionSpots']);
        $matches->setTvDeal($defaults['tvDeal']);
        $matches->setPrizeMoney($defaults['prizeMoney']);
        $matches->setLeaguePositionPot($defaults['leaguePositionPot']);
        $matches->setSponsorCount($defaults['sponsorCount']);
        $matches->setTrophy($defaults['trophy']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $this->open();

        self::assertSame('1', $crawler->filter(\sprintf('input[value="%s"]', (string) $drifted->getId()))->attr('data-differs'));
        self::assertSame('', (string) $crawler->filter(\sprintf('input[value="%s"]', (string) $matches->getId()))->attr('data-differs'));
    }

    public function testMoneyIsRenderedInPoundsWithSeparators(): void
    {
        // Stored as pence; the field edits pounds, formatted, and parseMoneyToPence() strips
        // the separators on the way back.
        $this->seedLeague(6, 1234500);

        self::assertStringContainsString('12,345', $this->open()->html());
    }
}
