<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the custom appearance widget (Task 8) actually renders on the
 * EasyAdmin player edit page — i.e. the `appearance_widget` form-theme block
 * is picked up, the AppearanceType child dropdowns render, and the live-preview
 * container + compositor asset are present. This is the server-side half of the
 * verification; the live on-change SVG update is browser-only.
 */
class PlayerAppearanceEditPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'appearance-edit-test-admin@example.com';

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

    public function testEditPageRendersAppearanceEditor(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $player = new Player('Avatar', 'Tester');
        $em->persist($player); // prePersist subscriber fills appearance
        $em->flush();
        $id = (string) $player->getId();

        try {
            $crawler = $client->request('GET', "/admin/player/{$id}/edit");

            $this->assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();

            // The custom widget block rendered (not the default child loop)
            $this->assertGreaterThan(
                0,
                $crawler->filter('[data-appearance-editor]')->count(),
                'appearance editor container should be present',
            );
            $this->assertGreaterThan(
                0,
                $crawler->filter('[data-appearance-preview]')->count(),
                'live preview container should be present',
            );

            // Compositor asset referenced
            $this->assertStringContainsString('admin/avatar-compositor.js', $html);

            // Child dropdowns rendered (EasyAdmin ids end with _<childName>)
            $this->assertGreaterThan(
                0,
                $crawler->filter('select[id$="_skinTone"]')->count(),
                'skinTone dropdown should render',
            );
            $this->assertGreaterThan(
                0,
                $crawler->filter('select[id$="_hairStyle"]')->count(),
                'hairStyle dropdown should render',
            );
        } finally {
            $em->remove($player);
            $em->flush();
        }
    }
}
