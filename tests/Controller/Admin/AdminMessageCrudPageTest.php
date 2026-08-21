<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\AdminMessage;
use App\Entity\AudienceGroup;
use App\Enum\AudienceCriteriaType;
use App\Service\AdminMessageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the messaging admin: that the CRUD pages render (enum ChoiceFields and the
 * JsonTextareaType on a Doctrine json column both throw at render time, not at boot), and
 * that admin-authored HTML is sanitised before it reaches the database.
 */
class AdminMessageCrudPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'admin-message-crud-test-admin@example.com';

    /** Mirrors the persisted-admin login used elsewhere (see SocialAuthControllerTest). */
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

    public function testMessageIndexAndNewPagesRender(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/admin-message');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/admin/admin-message/new');
        $this->assertResponseIsSuccessful();
    }

    public function testAudienceGroupPagesRenderIncludingTheJsonCriteriaField(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        foreach ($em->getRepository(AudienceGroup::class)->findBy(['slug' => 'crud-page-probe']) as $stale) {
            $em->remove($stale);
        }
        $em->flush();

        $group = new AudienceGroup('Crud Page Probe', 'crud-page-probe');
        $group->setCriteriaType(AudienceCriteriaType::DYNAMIC);
        $group->setCriteriaPayload(['minReputation' => 100, 'leagueTier' => [7, 8]]);
        $em->persist($group);
        $em->flush();
        $id = (string) $group->getId();

        $client->request('GET', '/admin/audience-group');
        $this->assertResponseIsSuccessful();

        // The edit page is where JsonTextareaType gets EasyAdmin's CollectionType options
        // injected, because criteriaPayload is a Doctrine `json` column.
        $client->request('GET', '/admin/audience-group/' . $id . '/edit');
        $this->assertResponseIsSuccessful();

        $em->remove($em->find(AudienceGroup::class, $group->getId()));
        $em->flush();
    }

    /**
     * The acceptance criterion for theme isolation: nothing an admin types can carry styling
     * or script into the client.
     */
    public function testSanitizerStripsScriptsStylesAndDisallowedTags(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(AdminMessageService::class);

        $clean = $service->sanitize(
            '<script>alert(1)</script>'
            . '<p style="color:red" class="danger">Season <strong>2</strong></p>'
            . '<iframe src="https://evil.example"></iframe>'
            . '<img src="x" onerror="alert(1)">'
            . '<a href="javascript:alert(1)">bad link</a>',
        );

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringNotContainsString('style=', $clean);
        $this->assertStringNotContainsString('class=', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);

        // Allowlisted markup survives intact.
        $this->assertStringContainsString('<p>', $clean);
        $this->assertStringContainsString('<strong>2</strong>', $clean);
    }

    public function testSanitizerKeepsAllowedFormattingAndHttpsLinks(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(AdminMessageService::class);

        $clean = $service->sanitize(
            '<h3>Patch notes</h3><ul><li>Fixed <em>a thing</em></li></ul>'
            . '<a href="https://buildmyclub.example/notes">Read more</a>',
        );

        $this->assertStringContainsString('<h3>Patch notes</h3>', $clean);
        $this->assertStringContainsString('<li>Fixed <em>a thing</em></li>', $clean);
        $this->assertStringContainsString('href="https://buildmyclub.example/notes"', $clean);
        // force_attributes hardens every outbound link.
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
    }

    public function testSanitizedBodyIsWhatGetsPersistedThroughTheCrudController(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/admin/admin-message/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="AdminMessage"]')->form();
        $form['AdminMessage[title]']    = 'Sanitiser probe';
        $form['AdminMessage[bodyHtml]'] = '<script>alert(1)</script><p style="color:red">Hi</p>';

        $client->submit($form);

        $message = $em->getRepository(AdminMessage::class)->findOneBy(['title' => 'Sanitiser probe']);

        $this->assertNotNull($message, 'Expected the message to be created.');
        $this->assertStringNotContainsString('<script', $message->getBodyHtml());
        $this->assertStringNotContainsString('style=', $message->getBodyHtml());
        $this->assertStringContainsString('<p>Hi</p>', $message->getBodyHtml());

        $em->remove($message);
        $em->flush();
    }
}
