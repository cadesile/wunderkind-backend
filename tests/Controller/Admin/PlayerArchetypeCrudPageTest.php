<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\PlayerArchetype;
use App\Enum\ArchetypePolarity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke-proves the archetype admin renders after the polarity refactor — specifically that the
 * polarity ChoiceField (backed by an enum) and the renamed traitWeightsJson virtual property
 * both resolve. A wrong field name here throws at render time, not at boot.
 */
class PlayerArchetypeCrudPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'archetype-crud-test-admin@example.com';

    /** Mirrors the persisted-admin login used elsewhere (see SocialAuthControllerTest). */
    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }
        $client->loginUser($admin, 'admin');
    }

    public function testIndexAndEditPagesRender(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Clear any probe row a previously aborted run left behind — slug and name are both
        // unique, so a leftover fixture would fail every subsequent run.
        foreach ($em->getRepository(PlayerArchetype::class)->findBy(['slug' => 'crud_page_probe']) as $stale) {
            $em->remove($stale);
        }
        $em->flush();

        $archetype = new PlayerArchetype(
            'crud_page_probe',
            'Crud Page Probe',
            'Temporary fixture for the admin render smoke test.',
            ArchetypePolarity::NEGATIVE,
            ['formula' => ['ambition' => 0.5, 'loyalty' => -0.5], 'threshold' => 65],
        );
        $em->persist($archetype);
        $em->flush();
        $id = (string) $archetype->getId();

        try {
            $client->request('GET', '/admin/player-archetype');
            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('crud_page_probe', (string) $client->getResponse()->getContent());

            $crawler = $client->request('GET', "/admin/player-archetype/{$id}/edit");
            $this->assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();

            $this->assertGreaterThan(
                0,
                $crawler->filter('select[id$="_polarity"]')->count(),
                'polarity dropdown should render on the archetype edit page',
            );
            $this->assertGreaterThan(
                0,
                $crawler->filter('textarea[id$="_traitWeightsJson"]')->count(),
                'trait weights editor should render under its renamed property',
            );
            $this->assertGreaterThan(
                0,
                $crawler->filter('input[id$="_slug"]')->count(),
                'slug field should render on the archetype edit page',
            );

            // The help text must advertise the real vocabulary, never the retired phantom traits.
            $this->assertStringContainsString('determination', $html);
            $this->assertStringNotContainsString('bravery', $html);
        } finally {
            // The request cycle detaches the fixture, so re-fetch before removing it.
            $fresh = $em->getRepository(PlayerArchetype::class)->find($id);
            if ($fresh !== null) {
                $em->remove($fresh);
                $em->flush();
            }
        }
    }
}
