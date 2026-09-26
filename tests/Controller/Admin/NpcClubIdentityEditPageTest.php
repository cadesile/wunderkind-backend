<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\NpcClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the "Kit & Badge" widget actually renders on NpcClub's EasyAdmin edit
 * page — the `kit_identity_widget` form-theme block is picked up, the
 * KitIdentityType child dropdowns render for both the home and away kit
 * variants, and the live-preview container + compositor assets are present.
 * Also proves primaryColor/secondaryColor are no longer independently
 * editable — the home kit is the single source of truth for those now (see
 * NpcClub::setIdentity()). Server-side half of the verification; the live
 * on-change SVG update and the swatch/grid picker overlay are browser-only.
 */
class NpcClubIdentityEditPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'npc-club-identity-edit-test-admin@example.com';

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

    public function testEditPageRendersKitBadgeWidgetForBothVariants(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $club = new NpcClub('Test Kit FC', 'EN', 4, 40, '#111111', '#eeeeee', 1_000_000, []);
        $em->persist($club);
        $em->flush();
        $id = (string) $club->getId();

        try {
            $crawler = $client->request('GET', "/admin/npc-club/{$id}/edit");

            $this->assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();

            $this->assertGreaterThan(0, $crawler->filter('[data-kit-badge-editor]')->count(), 'kit/badge editor container should be present');
            $this->assertGreaterThan(0, $crawler->filter('[data-kb-preview]')->count(), 'live preview container should be present');
            $this->assertStringContainsString('assets/kit-compositor.js', $html);
            $this->assertStringContainsString('assets/kit-identity-widget.js', $html);
            $this->assertStringNotContainsString('admin/kit-compositor.js', $html);

            $this->assertGreaterThan(0, $crawler->filter('select[id$="_homeKit"]')->count(), 'home kit dropdown should render');
            $this->assertGreaterThan(0, $crawler->filter('select[id$="_awayKit"]')->count(), 'away kit dropdown should render');
            $this->assertGreaterThan(0, $crawler->filter('select[id$="_badgeShape"]')->count(), 'badgeShape dropdown should render');
            $this->assertGreaterThan(0, $crawler->filter('input[id$="_initials"]')->count(), 'initials text field should render');

            // primaryColor/secondaryColor are no longer independently editable —
            // the home kit's colors are the single source of truth.
            $this->assertSame(0, $crawler->filter('input[id$="_primaryColor"]')->count(), 'primaryColor should not render on the edit form any more');
            $this->assertSame(0, $crawler->filter('input[id$="_secondaryColor"]')->count(), 'secondaryColor should not render on the edit form any more');
        } finally {
            $em->remove($em->getRepository(NpcClub::class)->find($id));
            $em->flush();
        }
    }

    public function testSubmittingEditFormPersistsIdentityAndSyncsColors(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $club = new NpcClub('Test Kit FC 2', 'EN', 4, 40, '#111111', '#eeeeee', 1_000_000, []);
        $em->persist($club);
        $em->flush();
        $id = (string) $club->getId();

        try {
            $crawler = $client->request('GET', "/admin/npc-club/{$id}/edit");
            $form    = $crawler->filter('#edit-NpcClub-form')->form();

            $form['NpcClub[identity][homeKit]']       = 'hoops';
            $form['NpcClub[identity][homePrimary]']   = '#1f8a4c';
            $form['NpcClub[identity][homeSecondary]'] = '#f4f3ee';
            $form['NpcClub[identity][awayKit]']        = 'sash';
            $form['NpcClub[identity][badgeShape]']     = 'round';
            $form['NpcClub[identity][initials]']       = 'afc';

            $client->submit($form);
            $this->assertResponseRedirects();

            $em->clear();
            /** @var NpcClub $reloaded */
            $reloaded = $em->getRepository(NpcClub::class)->find($id);
            $identity = $reloaded->getIdentity();

            $this->assertSame('hoops', $identity['home']['kit']);
            $this->assertSame('sash', $identity['away']['kit']);
            $this->assertSame('round', $identity['badgeShape']);
            $this->assertSame('AFC', $identity['initials']);

            // The entity's own primaryColor/secondaryColor must now mirror the home kit.
            $this->assertSame('#1f8a4c', $reloaded->getPrimaryColor());
            $this->assertSame('#f4f3ee', $reloaded->getSecondaryColor());
        } finally {
            $managed = $em->getRepository(NpcClub::class)->find($id);
            if ($managed !== null) {
                $em->remove($managed);
            }
            $em->flush();
        }
    }
}
