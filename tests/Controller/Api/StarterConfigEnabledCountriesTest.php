<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Location\CountryProfile;
use App\Enum\Location\CountryProfileStatus;
use App\Repository\Location\CountryProfileRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Which countries `GET /api/starter-config` offers, now that the admin checkboxes are gone.
 *
 * THE KEYS ARE THE CONTRACT AND THEY DID NOT CHANGE. `enabledCountries` and
 * `enabledCountryOptions` keep their names and shapes; only what fills them moved, from a
 * hand-ticked list on the starter config to every country whose profile is LIVE. The app ships
 * behind the backend, so these assertions are what say the switchover is invisible to it.
 *
 * ONE CLIENT, CREATED IN setUp(). WebTestCase refuses a second kernel boot, so the container
 * has to come from the client rather than from bootKernel().
 */
class StarterConfigEnabledCountriesTest extends WebTestCase
{
    /** A code no Country case uses, so it cannot collide with seeded data. */
    private const CODE = 'ZQ';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CountryProfileRepository $profiles;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // The client reboots the kernel between requests by default, which resets the entity
        // manager and silently detaches anything held across a fetch() — a later flush() then
        // writes nothing and the assertion fails for a reason that has nothing to do with the
        // endpoint. These tests change a status, read, change it again, so the kernel has to
        // stay put.
        $this->client->disableReboot();

        $this->em       = $this->client->getContainer()->get(EntityManagerInterface::class);
        $this->profiles = $this->client->getContainer()->get(CountryProfileRepository::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $this->client->getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM country_profile WHERE code = :c',
            ['c' => self::CODE],
        );
        $this->em->clear();
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $this->client->request('GET', '/api/starter-config');

        $this->assertResponseStatusCodeSame(200);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function profileAt(CountryProfileStatus $status): CountryProfile
    {
        $profile = $this->profiles->findByCode(self::CODE) ?? new CountryProfile(self::CODE, 'Testovia');

        // The row has to exist before moveTo() can UPDATE it.
        $this->em->persist($profile);
        $this->em->flush();

        $this->moveTo($profile, $status);

        return $profile;
    }

    /**
     * Raw UPDATE rather than setStatus() + flush().
     *
     * These tests change a status between requests, and anything the request cycle does to the
     * entity manager — a clear(), a reboot — detaches the entity so the flush writes nothing
     * and the next assertion fails for a reason that has nothing to do with the endpoint. One
     * statement against the row is immune to all of it.
     */
    private function moveTo(CountryProfile $profile, CountryProfileStatus $status): void
    {
        $this->client->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE country_profile SET status = :s WHERE code = :c',
            ['s' => $status->value, 'c' => self::CODE],
        );
        $this->em->clear();
    }

    public function testBothKeysSurviveTheMoveOffTheStarterConfig(): void
    {
        $data = $this->fetch();

        $this->assertArrayHasKey('enabledCountries', $data);
        $this->assertArrayHasKey('enabledCountryOptions', $data);
        $this->assertIsArray($data['enabledCountries']);

        foreach ($data['enabledCountryOptions'] as $option) {
            $this->assertSame(['code', 'label'], array_keys($option));
        }
    }

    public function testOnlyALiveProfileIsOffered(): void
    {
        $profile = $this->profileAt(CountryProfileStatus::DRAFT);
        $this->assertNotContains(self::CODE, $this->fetch()['enabledCountries'], 'draft is not playable');

        $this->moveTo($profile, CountryProfileStatus::TIER_READY);
        $this->assertNotContains(self::CODE, $this->fetch()['enabledCountries'], 'tier-ready is not playable');

        $this->moveTo($profile, CountryProfileStatus::LIVE);
        $this->assertContains(self::CODE, $this->fetch()['enabledCountries'], 'live is playable');

        // Withdrawn: existing claims keep working, but nothing new is offered.
        $this->moveTo($profile, CountryProfileStatus::RETIRED);
        $this->assertNotContains(self::CODE, $this->fetch()['enabledCountries'], 'retired is not playable');
    }

    public function testOnlyCodesTheCountryEnumKnowsBecomeOptions(): void
    {
        // enabledCountryOptions resolves labels through the Country enum, so a live profile
        // whose code has no enum case appears in the raw codes but cannot become an option —
        // the client's picker would have nothing to show for it.
        $this->profileAt(CountryProfileStatus::LIVE);

        $data = $this->fetch();

        $this->assertContains(self::CODE, $data['enabledCountries']);
        $this->assertNotContains(
            self::CODE,
            array_column($data['enabledCountryOptions'], 'code'),
            'no enum case, so no label, so no option',
        );
    }
}
