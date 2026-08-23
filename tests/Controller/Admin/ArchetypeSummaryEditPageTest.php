<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Player;
use App\Entity\Staff;
use App\Service\ArchetypeResolverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the resolved-archetype panel renders inside the Personality fieldset on the
 * Player and Staff edit pages, showing what ArchetypeResolverService resolved for the
 * entity being edited.
 *
 * Mirrors PlayerAppearanceEditPageTest — a custom form type reached through a
 * form-theme block only actually renders if the theme is registered on the CRUD
 * controller, which nothing but a real page request proves.
 *
 * The scoring maths itself is pinned against a controlled catalogue in
 * ArchetypeResolverServiceTest; here the catalogue is whatever is seeded, so the
 * assertions compare the rendered panel to the resolver's own output rather than to
 * hardcoded archetype names.
 */
class ArchetypeSummaryEditPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'archetype-summary-test-admin@example.com';

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

    public function testPlayerEditPageRendersResolvedArchetypes(): void
    {
        $player = new Player('Archetype', 'Tester');
        $player->getPersonality()->setDetermination(20);
        $player->getPersonality()->setProfessionalism(20);
        $player->getPersonality()->setTemperament(1);

        $this->assertPanelRenders($player, 'player');
    }

    public function testStaffEditPageRendersResolvedArchetypes(): void
    {
        $staff = new Staff('Archetype', 'Coach');
        $staff->getPersonality()->setDetermination(20);
        $staff->getPersonality()->setProfessionalism(20);
        $staff->getPersonality()->setTemperament(1);

        $this->assertPanelRenders($staff, 'staff');
    }

    /**
     * Persists $entity, requests its EasyAdmin edit page, and asserts the panel rendered
     * both polarities with the resolver's verdict. $adminSlug is the EasyAdmin pretty-URL
     * segment (/admin/{slug}/{id}/edit).
     */
    private function assertPanelRenders(object $entity, string $adminSlug): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($entity);
        $em->flush();
        $id = (string) $entity->getId();

        $resolver = self::getContainer()->get(ArchetypeResolverService::class);
        $expected = $resolver->resolve($entity->getPersonality());

        $this->assertNotNull(
            $expected['positive'],
            'the archetype catalogue must be seeded for this test to be meaningful — run app:seed-archetypes',
        );
        $this->assertNotNull($expected['negative']);

        try {
            $crawler = $client->request('GET', "/admin/{$adminSlug}/{$id}/edit");

            $this->assertResponseIsSuccessful();

            $this->assertGreaterThan(
                0,
                $crawler->filter('[data-archetype-summary]')->count(),
                "archetype panel should be present on the {$adminSlug} edit page",
            );

            foreach (['positive', 'negative'] as $polarity) {
                $card = $crawler->filter('[data-archetype-card="' . $polarity . '"]');

                $this->assertSame(1, $card->count(), "one {$polarity} card should render");
                $this->assertSame(
                    $expected[$polarity]['name'],
                    trim($card->filter('[data-archetype-name]')->text()),
                    "{$polarity} card should name the resolved archetype",
                );
                $this->assertSame(
                    $expected[$polarity]['score'] . ' / ' . $expected[$polarity]['threshold'],
                    trim($card->filter('[data-archetype-score]')->text()),
                );
                $this->assertSame(
                    $expected[$polarity]['matched'] ? 'Matched' : 'Below threshold (closest match)',
                    trim($card->filter('[data-archetype-status]')->text()),
                );
            }

            // The scorer needs the catalogue client-side to recompute as traits are edited.
            $this->assertNotEmpty(
                $crawler->filter('[data-archetype-summary]')->attr('data-catalogue'),
                'the catalogue must be embedded for the live rescoring script',
            );
        } finally {
            $em->remove($entity);
            $em->flush();
        }
    }
}
