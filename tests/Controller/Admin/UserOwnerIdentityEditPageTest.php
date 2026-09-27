<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the Owner Identity fieldset (name/nationality/gender/dob) and the
 * appearance widget both render on User's EasyAdmin EDIT page, and that EDIT
 * is actually enabled (it was fully disabled before this change) — same
 * server-side verification style as PlayerAppearanceEditPageTest.
 */
class UserOwnerIdentityEditPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'user-owner-identity-edit-test-admin@example.com';

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

    public function testEditPageRendersOwnerIdentityAndAppearanceEditor(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('owner-edit-page-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();
        $id = (string) $user->getId();

        try {
            $crawler = $client->request('GET', "/admin/user/{$id}/edit");

            $this->assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();

            $this->assertGreaterThan(0, $crawler->filter('input[id$="_name"]')->count(), 'name field should render');
            $this->assertGreaterThan(0, $crawler->filter('input[id$="_nationality"]')->count(), 'nationality field should render');
            $this->assertGreaterThan(0, $crawler->filter('select[id$="_gender"]')->count(), 'gender field should render');
            $this->assertGreaterThan(0, $crawler->filter('input[id$="_dob"]')->count(), 'dob field should render');

            $this->assertGreaterThan(0, $crawler->filter('[data-appearance-editor]')->count(), 'appearance editor container should be present');
            $this->assertStringContainsString('assets/avatar-compositor.js', $html);
            $this->assertGreaterThan(0, $crawler->filter('select[id$="_skin"]')->count(), 'skin dropdown should render');
            $this->assertSame(
                'staff',
                $crawler->filter('[data-appearance-editor]')->attr('data-person-type'),
                'owner avatar should render with the staff body shape',
            );
        } finally {
            $managed = $em->getRepository(User::class)->find($id);
            if ($managed !== null) {
                $em->remove($managed);
            }
            $em->flush();
        }
    }

    public function testSubmittingEditFormPersistsOwnerIdentity(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('owner-edit-submit-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();
        $id = (string) $user->getId();

        try {
            $crawler = $client->request('GET', "/admin/user/{$id}/edit");
            $form    = $crawler->filter('#edit-User-form')->form();

            $form['User[name]']        = 'Admin-Set Owner';
            $form['User[nationality]'] = 'Brazilian';
            $form['User[gender]']      = 'female';
            $form['User[dob]']         = '1985-03-20';

            $client->submit($form);
            $this->assertResponseRedirects();

            $em->clear();
            /** @var User $reloaded */
            $reloaded = $em->getRepository(User::class)->find($id);

            $this->assertSame('Admin-Set Owner', $reloaded->getName());
            $this->assertSame('Brazilian', $reloaded->getNationality());
            $this->assertSame('female', $reloaded->getGender());
            $this->assertSame('1985-03-20', $reloaded->getDob()->format('Y-m-d'));
        } finally {
            $managed = $em->getRepository(User::class)->find($id);
            if ($managed !== null) {
                $em->remove($managed);
            }
            $em->flush();
        }
    }
}
