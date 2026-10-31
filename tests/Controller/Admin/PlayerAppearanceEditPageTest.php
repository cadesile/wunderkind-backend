<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Agent;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the custom appearance widget (Task 8) actually renders on every
 * EasyAdmin edit page it was wired into — Player, Staff, Scout and Agent —
 * i.e. the `appearance_widget` form-theme block is picked up, the
 * AppearanceType child dropdowns render, and the live-preview container +
 * compositor asset are present. This is the server-side half of the
 * verification; the live on-change SVG update and the swatch/grid picker
 * overlay built by appearance-widget.js are browser-only.
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
        $this->assertEditorRenders(new Player('Avatar', 'Tester'), 'player', 'player');
    }

    public function testStaffEditPageRendersAppearanceEditor(): void
    {
        $this->assertEditorRenders(new Staff('Avatar', 'Coach'), 'staff', 'staff');
    }

    public function testScoutEditPageRendersAppearanceEditor(): void
    {
        $this->assertEditorRenders(new Scout('Avatar Scout'), 'scout', 'staff');
    }

    public function testAgentEditPageRendersAppearanceEditor(): void
    {
        $this->assertEditorRenders(new Agent('Avatar Agent'), 'agent', 'staff');
    }

    /**
     * Persists $entity (prePersist fills its appearance), requests its EasyAdmin
     * edit page, and asserts the appearance editor + preview + compositor asset
     * + dropdowns render. $adminSlug is the EasyAdmin pretty-URL segment
     * (/admin/{slug}/{id}/edit). $expectedPersonType is the person_type the
     * widget should have been wired with (player-shaped vs staff-shaped).
     */
    private function assertEditorRenders(object $entity, string $adminSlug, string $expectedPersonType): void
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
            // AvatarExtras — accessories and tattoos. These are stored FLAT beside the
            // fifteen config keys, so every one of them is a real form field on every
            // entity type; which ones the WIDGET offers is role-gated in the template,
            // and which ones the SPRITE honours is role-gated by the sprite itself.
            foreach (['earring', 'armband', 'wristband', 'snood', 'headwear', 'neckwear', 'tattoos'] as $extra) {
                $this->assertGreaterThan(
                    0,
                    $crawler->filter(sprintf('[id$="_%s"]', $extra))->count(),
                    "{$extra} field should render on the {$adminSlug} edit page",
                );
            }

            // `tattoos` is a LIST — a multi-select, not a single-value dropdown. If this
            // ever regresses to a plain select, a player can hold exactly one tattoo.
            $this->assertGreaterThan(
                0,
                $crawler->filter('select[id$="_tattoos"][multiple]')->count(),
                "tattoos should be a multi-select on the {$adminSlug} edit page",
            );

            $this->assertStringContainsString('data-ap-mode="multi"', $html);
            $this->assertStringContainsString('Accessories', $html);
            $this->assertStringContainsString('Tattoos', $html);

            $this->assertGreaterThan(
                0,
                $crawler->filter('select[id$="_skin"]')->count(),
                "skin dropdown should render on the {$adminSlug} edit page",
            );
            $this->assertGreaterThan(
                0,
                $crawler->filter('select[id$="_hair"]')->count(),
                "hair dropdown should render on the {$adminSlug} edit page",
            );
            $this->assertSame(
                $expectedPersonType,
                $crawler->filter('[data-appearance-editor]')->attr('data-person-type'),
                "person_type should be {$expectedPersonType} on the {$adminSlug} edit page",
            );
        } finally {
            $em->remove($entity);
            $em->flush();
        }
    }
}
