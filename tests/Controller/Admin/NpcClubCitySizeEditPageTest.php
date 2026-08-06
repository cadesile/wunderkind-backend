<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the region/citySize/populationSize/isCapital fields added to NpcClub
 * actually render on its EasyAdmin edit page, and that submitting the form
 * persists the new values. `region` renders as a dropdown of distinct region
 * values already in use (NpcClubRepository::findDistinctRegions()), not free
 * text, so both tests select an option rather than typing a value.
 */
class NpcClubCitySizeEditPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'npc-club-city-size-edit-test-admin@example.com';

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }
        $client->loginUser($admin, 'admin');
    }

    /** Finds an <option> by its exact visible label text and returns its `value` attribute. */
    private function optionValueByLabel(\Symfony\Component\DomCrawler\Crawler $select, string $label): ?string
    {
        foreach ($select->filter('option') as $option) {
            if (trim($option->textContent) === $label) {
                return $option->getAttribute('value');
            }
        }
        return null;
    }

    public function testEditPageRendersCitySizeFields(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $club = new NpcClub(
            'Test City FC', 'EN', 4, 40, '#111111', '#eeeeee', 1_000_000, [],
            region: 'Greater London',
            citySize: CitySize::BIG,
            populationSize: 8_982_000,
            isCapital: true,
        );
        $em->persist($club);
        $em->flush();
        $id = (string) $club->getId();

        try {
            $crawler = $client->request('GET', "/admin/npc-club/{$id}/edit");

            $this->assertResponseIsSuccessful();

            $regionSelect = $crawler->filter('select[id$="_region"]');
            $this->assertGreaterThan(0, $regionSelect->count(), 'region field should render as a dropdown');
            $this->assertGreaterThan(0, $crawler->filter('select[id$="_citySize"]')->count(), 'citySize field should render');
            $this->assertGreaterThan(0, $crawler->filter('input[id$="_populationSize"]')->count(), 'populationSize field should render');
            $this->assertGreaterThan(0, $crawler->filter('input[id$="_isCapital"]')->count(), 'isCapital field should render');

            $this->assertNotNull(
                $this->optionValueByLabel($regionSelect, 'Greater London'),
                'the region dropdown should include the club\'s own region as an option',
            );
            $this->assertSame('8982000', $crawler->filter('input[id$="_populationSize"]')->attr('value'));
        } finally {
            $em->remove($em->getRepository(NpcClub::class)->find($id));
            $em->flush();
        }
    }

    public function testSubmittingEditFormPersistsCitySizeFields(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);

        // A second, unrelated club whose region ("Test Target Region") we'll select
        // from the dropdown for the club under edit — proves the dropdown is
        // sourced from *existing* region values, not free text.
        $regionSource = new NpcClub(
            'Region Source FC', 'EN', 4, 40, '#111111', '#eeeeee', 1_000_000, [],
            region: 'Test Target Region',
        );
        $em->persist($regionSource);

        $club = new NpcClub('Test City FC 2', 'EN', 4, 40, '#111111', '#eeeeee', 1_000_000, []);
        $em->persist($club);
        $em->flush();
        $id = (string) $club->getId();

        try {
            $crawler = $client->request('GET', "/admin/npc-club/{$id}/edit");

            $fields = $crawler->filter('select[id$="_region"], select[id$="_citySize"], input[id$="_populationSize"], input[id$="_isCapital"]');
            $this->assertGreaterThanOrEqual(4, $fields->count(), 'expected all 4 new fields present on the form before submitting');

            $form = $crawler->filter('#edit-NpcClub-form')->form();

            $regionSelect       = $crawler->filter('select[id$="_region"]');
            $citySizeSelect     = $crawler->filter('select[id$="_citySize"]');
            $regionName         = $regionSelect->attr('name');
            $citySizeName       = $citySizeSelect->attr('name');
            $populationSizeName = $crawler->filter('input[id$="_populationSize"]')->attr('name');
            $isCapitalName      = $crawler->filter('input[id$="_isCapital"]')->attr('name');

            // Both ChoiceFields render index-based option values, not the underlying
            // string/enum value — select by matching each option's label text.
            $regionOptionValue = $this->optionValueByLabel($regionSelect, 'Test Target Region');
            $this->assertNotNull($regionOptionValue, 'expected "Test Target Region" as a region dropdown option');

            $smallOptionValue = $this->optionValueByLabel($citySizeSelect, 'Small');
            $this->assertNotNull($smallOptionValue, 'expected a "Small" option in the citySize dropdown');

            $form[$regionName]         = $regionOptionValue;
            $form[$citySizeName]       = $smallOptionValue;
            $form[$populationSizeName] = '12345';
            $form->offsetSet($isCapitalName, true);

            $client->submit($form);

            $this->assertResponseRedirects();

            $em->clear();
            /** @var NpcClub $reloaded */
            $reloaded = $em->getRepository(NpcClub::class)->find($id);
            $this->assertSame('Test Target Region', $reloaded->getRegion());
            $this->assertSame(CitySize::SMALL, $reloaded->getCitySize());
            $this->assertSame(12345, $reloaded->getPopulationSize());
            $this->assertTrue($reloaded->isCapital());
        } finally {
            foreach ([$id, (string) $regionSource->getId()] as $cleanupId) {
                $managed = $em->getRepository(NpcClub::class)->find($cleanupId);
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }
    }
}
