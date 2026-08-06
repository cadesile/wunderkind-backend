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
 * persists the new values.
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

            $this->assertGreaterThan(0, $crawler->filter('input[id$="_region"]')->count(), 'region field should render');
            $this->assertGreaterThan(0, $crawler->filter('select[id$="_citySize"]')->count(), 'citySize field should render');
            $this->assertGreaterThan(0, $crawler->filter('input[id$="_populationSize"]')->count(), 'populationSize field should render');
            $this->assertGreaterThan(0, $crawler->filter('input[id$="_isCapital"]')->count(), 'isCapital field should render');

            $this->assertSame('Greater London', $crawler->filter('input[id$="_region"]')->attr('value'));
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

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $club = new NpcClub('Test City FC 2', 'EN', 4, 40, '#111111', '#eeeeee', 1_000_000, []);
        $em->persist($club);
        $em->flush();
        $id = (string) $club->getId();

        try {
            $crawler = $client->request('GET', "/admin/npc-club/{$id}/edit");

            $fields = $crawler->filter('input[id$="_region"], select[id$="_citySize"], input[id$="_populationSize"], input[id$="_isCapital"]');
            $this->assertGreaterThanOrEqual(4, $fields->count(), 'expected all 4 new fields present on the form before submitting');

            $form = $crawler->filter('#edit-NpcClub-form')->form();

            $regionName         = $crawler->filter('input[id$="_region"]')->attr('name');
            $citySizeName       = $crawler->filter('select[id$="_citySize"]')->attr('name');
            $populationSizeName = $crawler->filter('input[id$="_populationSize"]')->attr('name');
            $isCapitalName      = $crawler->filter('input[id$="_isCapital"]')->attr('name');

            // ChoiceField renders index-based option values ("0"/"1"/"2" for Big/Medium/Small),
            // not the enum's own backed value — select by matching the option's label text.
            $smallOptionValue = null;
            foreach ($crawler->filter('select[id$="_citySize"]')->filter('option') as $option) {
                if (trim($option->textContent) === 'Small') {
                    $smallOptionValue = $option->getAttribute('value');
                    break;
                }
            }
            $this->assertNotNull($smallOptionValue, 'expected a "Small" option in the citySize dropdown');

            $form[$regionName]         = 'Test Region';
            $form[$citySizeName]       = $smallOptionValue;
            $form[$populationSizeName] = '12345';
            $form->offsetSet($isCapitalName, true);

            $client->submit($form);

            $this->assertResponseRedirects();

            $em->clear();
            /** @var NpcClub $reloaded */
            $reloaded = $em->getRepository(NpcClub::class)->find($id);
            $this->assertSame('Test Region', $reloaded->getRegion());
            $this->assertSame(CitySize::SMALL, $reloaded->getCitySize());
            $this->assertSame(12345, $reloaded->getPopulationSize());
            $this->assertTrue($reloaded->isCapital());
        } finally {
            $managed = $em->getRepository(NpcClub::class)->find($id);
            if ($managed !== null) {
                $em->remove($managed);
                $em->flush();
            }
        }
    }
}
