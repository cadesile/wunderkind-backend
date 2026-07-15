<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the custom appearance widget (Task 8) actually renders on every
 * EasyAdmin edit page it was wired into — Player, Staff and Scout — i.e. the
 * `appearance_widget` form-theme block is picked up, the AppearanceType child
 * dropdowns render, and the live-preview container + compositor asset are
 * present. This is the server-side half of the verification; the live on-change
 * SVG update is browser-only.
 *
 * It also guards the EasyAdmin regression fixed alongside it: a `json` property
 * makes EasyAdmin inject CollectionType options into the custom form type, which
 * without the tolerance in AppearanceType::configureOptions() throws on every
 * one of these three edit pages.
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

    public function testPlayerEditPageRendersAppearanceEditor(): void
    {
        $this->assertEditorRenders(new Player('Avatar', 'Tester'), 'player');
    }

    public function testStaffEditPageRendersAppearanceEditor(): void
    {
        $this->assertEditorRenders(new Staff('Avatar', 'Coach'), 'staff');
    }

    public function testScoutEditPageRendersAppearanceEditor(): void
    {
        $this->assertEditorRenders(new Scout('Avatar Scout'), 'scout');
    }

    /**
     * Persists $entity (prePersist fills its appearance), requests its EasyAdmin
     * edit page, and asserts the appearance editor + preview + compositor asset
     * + dropdowns render. $adminSlug is the EasyAdmin pretty-URL segment
     * (/admin/{slug}/{id}/edit).
     */
    private function assertEditorRenders(object $entity, string $adminSlug): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($entity);
        $em->flush();
        $id = (string) $entity->getId();

        try {
            $crawler = $client->request('GET', "/admin/{$adminSlug}/{$id}/edit");

            $this->assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();

            $this->assertGreaterThan(
                0,
                $crawler->filter('[data-appearance-editor]')->count(),
                "appearance editor container should be present on the {$adminSlug} edit page",
            );
            $this->assertGreaterThan(
                0,
                $crawler->filter('[data-appearance-preview]')->count(),
                "live preview container should be present on the {$adminSlug} edit page",
            );
            // The compositor asset must live OUTSIDE public/admin/, otherwise the
            // public/admin/ directory shadows the EasyAdmin /admin route at the
            // nginx static layer and every /admin page 403s. Guard both facts.
            $this->assertStringContainsString('assets/avatar-compositor.js', $html);
            $this->assertStringNotContainsString('admin/avatar-compositor.js', $html);
            $this->assertGreaterThan(
                0,
                $crawler->filter('select[id$="_skinTone"]')->count(),
                "skinTone dropdown should render on the {$adminSlug} edit page",
            );
            $this->assertGreaterThan(
                0,
                $crawler->filter('select[id$="_hairStyle"]')->count(),
                "hairStyle dropdown should render on the {$adminSlug} edit page",
            );
        } finally {
            $em->remove($entity);
            $em->flush();
        }
    }
}
