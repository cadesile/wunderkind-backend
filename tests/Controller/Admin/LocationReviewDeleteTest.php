<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\LocationRival;
use App\Entity\NpcClub;
use App\Enum\Location\EnrichmentStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Delete selected" on the enrichment screen's "Needs a human" queue.
 *
 * The point of these tests is the refusals as much as the delete: a town anything real depends
 * on must survive, a town that is not on the review queue must survive a crafted POST, and a
 * tokenless POST must delete nothing.
 */
class LocationReviewDeleteTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'location-delete-test@example.com';
    private const COUNTRY     = 'ZD';

    public function testTheReviewQueueOffersADeleteButtonBehindAConfirm(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->town('Placeholderton', EnrichmentStatus::FAILED);

        $crawler = $this->openQueue($client);

        $button = $crawler->filter('button[formaction*="admin_location_delete_subset"]');
        self::assertCount(1, $button, 'the review queue should carry one delete button');
        self::assertNotNull($button->attr('data-bmc-dialog-title'), 'delete must confirm first');
    }

    public function testDeletingAFailedTownRemovesItAndItsUnclaimedPlots(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $doomed = $this->town('Placeholderton', EnrichmentStatus::FAILED);
        $kept   = $this->town('Keepsville', EnrichmentStatus::NEEDS_REVIEW);
        $id     = (string) $doomed->getId();

        $crawler = $this->openQueue($client);
        $form    = $crawler->selectButton('DELETE SELECTED')->form();
        foreach ($form['locations'] as $box) {
            if ($box->availableOptionValues() === [$id]) {
                $box->tick();
            }
        }
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM location WHERE id = :id', $id));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM land_plot WHERE location_id = :id', $id));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM location WHERE id = :id', (string) $kept->getId()), 'an unticked town must survive');

        $client->followRedirect();
        self::assertStringContainsStringIgnoringCase('Deleted 1 town(s): Placeholderton', (string) $client->getResponse()->getContent());
    }

    public function testATokenlessDeleteRemovesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $town = $this->town('Placeholderton', EnrichmentStatus::FAILED);

        $client->request('POST', '/admin/locations/enrichment/delete', ['locations' => [(string) $town->getId()]]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM location WHERE id = :id', (string) $town->getId()));
    }

    public function testATownWithAnNpcClubIsKeptAndTheFlashSaysWhy(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $town = $this->town('Clubtown', EnrichmentStatus::NEEDS_REVIEW);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new NpcClub('Clubtown Rovers', self::COUNTRY, 5, 10, '#000000', '#ffffff', 0, [], location: $town));
        $em->flush();

        $this->postDelete($client, [(string) $town->getId()]);

        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM location WHERE id = :id', (string) $town->getId()));
        $client->followRedirect();
        self::assertStringContainsStringIgnoringCase('Kept Clubtown', (string) $client->getResponse()->getContent());
    }

    /** The route takes ids from a POST body; it must not delete a good town if handed one. */
    public function testAnEnrichedTownCannotBeDeletedByACraftedPost(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->town('Placeholderton', EnrichmentStatus::FAILED); // so the queue renders its form
        $good = $this->town('Goodtown', EnrichmentStatus::ENRICHED);

        $this->postDelete($client, [(string) $good->getId()]);

        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM location WHERE id = :id', (string) $good->getId()));
    }

    /** A neighbour that named the deleted town as a rival is marked stale, not left pointing at a gap. */
    public function testNeighboursThatListedTheTownAsARivalAreMarkedStale(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $doomed    = $this->town('Placeholderton', EnrichmentStatus::FAILED);
        $neighbour = $this->town('Nextdoor', EnrichmentStatus::ENRICHED);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $neighbour->setRivalsComputedAt(new \DateTimeImmutable());
        $em->persist(new LocationRival($neighbour, $doomed, 1, 5.0));
        $em->flush();

        $this->postDelete($client, [(string) $doomed->getId()]);

        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM location WHERE id = :id', (string) $doomed->getId()));
        self::assertSame(
            1,
            $this->rows('SELECT COUNT(*) FROM location WHERE id = :id AND rivals_computed_at IS NULL', (string) $neighbour->getId()),
        );
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @param list<string> $ids */
    private function postDelete(KernelBrowser $client, array $ids): void
    {
        $crawler = $this->openQueue($client);
        $token   = $crawler->filter('input[name="_delete_token"]')->attr('value');

        $client->request('POST', '/admin?routeName=admin_location_delete_subset', [
            '_delete_token' => $token,
            'locations'     => $ids,
        ]);

        self::assertResponseRedirects();
    }

    private function openQueue(KernelBrowser $client): Crawler
    {
        $crawler = $client->request('GET', '/admin', [
            'routeName' => 'admin_location_enrichment',
            'country'   => self::COUNTRY,
        ]);

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function town(string $name, EnrichmentStatus $status): Location
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $country = $em->find(CountryProfile::class, self::COUNTRY);
        if ($country === null) {
            $country = new CountryProfile(self::COUNTRY, 'Delete Testland');
            $em->persist($country);
        }

        $slug     = strtolower($name);
        $location = new Location($country, $name, self::COUNTRY . '-' . $slug, $slug);
        $location->setPopulation(50_000);
        $location->setEnrichmentStatus($status);
        $em->persist($location);
        $em->persist(new LandPlot($location, 1));
        $em->flush();

        return $location;
    }

    private function rows(string $sql, string $id): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne($sql, ['id' => $id]);
    }

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $client->disableReboot();

        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    private function purge(): void
    {
        $c   = self::getContainer()->get(Connection::class);
        $sub = '(SELECT id FROM location WHERE country_code = :c)';
        $p   = ['c' => self::COUNTRY];

        $c->executeStatement('DELETE FROM npc_club WHERE country = :c', $p);
        $c->executeStatement("DELETE FROM location_rival WHERE location_id IN $sub OR rival_location_id IN $sub", $p);
        $c->executeStatement("DELETE FROM location_enrichment_attempt WHERE location_id IN $sub", $p);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN $sub", $p);
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', $p);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', $p);
    }
}
