<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Competition\CompetitionTemplate;
use App\Enum\Competition\CompetitionDuration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the competition-template admin, in particular that allowedTiersJson/
 * roundEngineConfigJson (CodeEditorField bound to virtual *Json properties, per
 * EditableJsonColumnTrait) round-trip correctly — this repo has a documented history
 * of EasyAdmin's json-column-to-CollectionType auto-configuration silently corrupting
 * data (see GameEventTemplateCrudPageTest), so a fresh JSON-editing controller needs
 * the same direct verification.
 */
class CompetitionTemplateCrudPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'competition-template-crud-test-admin@example.com';
    private const SLUG             = 'crud-page-probe-cup';

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

    private function removeTemplate(EntityManagerInterface $em): void
    {
        foreach ($em->getRepository(CompetitionTemplate::class)->findBy(['slug' => self::SLUG]) as $stale) {
            $em->remove($stale);
        }
        $em->flush();
    }

    public function testNewFormCreatesATemplateWithJsonFieldsRoundTripping(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeTemplate($em);

        $crawler = $client->request('GET', '/admin/competition-template/new');
        self::assertResponseIsSuccessful();

        // JSON columns are single code-editor inputs bound to the virtual *Json
        // properties, not nested collection widgets.
        foreach (['allowedTiersJson', 'roundEngineConfigJson'] as $property) {
            self::assertCount(
                1,
                $crawler->filter('[name="CompetitionTemplate[' . $property . ']"]'),
                sprintf('Expected a single editor bound to %s.', $property),
            );
        }

        $form = $crawler->selectButton('Create')->form();
        $form['CompetitionTemplate[name]']            = 'Crud Page Probe Cup';
        $form['CompetitionTemplate[slug]']             = self::SLUG;
        $form['CompetitionTemplate[entrantCapacity]']  = '8';
        $form['CompetitionTemplate[durationOption]']   = CompetitionDuration::ONE_DAY->value;
        $form['CompetitionTemplate[minClubReputation]'] = '25';
        $form['CompetitionTemplate[victorPrize]']       = '500000';
        $form['CompetitionTemplate[allowedTiersJson]']  = json_encode([1, 2]);
        $form['CompetitionTemplate[roundEngineConfigJson]'] = json_encode(['FINAL' => 'ai_narrative', 'default' => 'deterministic']);

        $client->submit($form);
        self::assertResponseRedirects();
        $em->clear();

        $saved = $em->getRepository(CompetitionTemplate::class)->findOneBy(['slug' => self::SLUG]);
        self::assertNotNull($saved, 'Template was not created.');
        self::assertSame('Crud Page Probe Cup', $saved->getName());
        self::assertSame(8, $saved->getEntrantCapacity());
        self::assertSame(CompetitionDuration::ONE_DAY, $saved->getDurationOption());
        self::assertSame(25, $saved->getMinClubReputation());
        self::assertSame(500000, $saved->getVictorPrize());
        self::assertSame([1, 2], $saved->getAllowedTiers());
        self::assertSame(['FINAL' => 'ai_narrative', 'default' => 'deterministic'], $saved->getRoundEngineConfig());

        $this->removeTemplate($em);
    }

    public function testInvalidJsonIsRejectedAndLeavesTheStoredValueIntact(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeTemplate($em);

        $template = new CompetitionTemplate('Crud Page Probe Cup', self::SLUG, 8, CompetitionDuration::ONE_DAY);
        $template->setAllowedTiers([1]);
        $em->persist($template);
        $em->flush();
        $id = (string) $template->getId();

        $crawler = $client->request('GET', '/admin/competition-template/' . $id . '/edit');
        $form    = $crawler->selectButton('Save changes')->form();
        $form['CompetitionTemplate[allowedTiersJson]'] = '[1, 2,]';

        $crawler = $client->submit($form);
        $em->clear();

        $after = $em->getRepository(CompetitionTemplate::class)->findOneBy(['slug' => self::SLUG]);
        self::assertSame([1], $after->getAllowedTiers(), 'Invalid JSON overwrote the stored allowedTiers.');
        self::assertStringContainsString('Invalid JSON', $crawler->text(), 'No validation error was shown.');

        $this->removeTemplate($em);
    }

    public function testClearingAllowedTiersJsonMeansNoRestriction(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeTemplate($em);

        $template = new CompetitionTemplate('Crud Page Probe Cup', self::SLUG, 8, CompetitionDuration::ONE_DAY);
        $template->setAllowedTiers([1]);
        $em->persist($template);
        $em->flush();
        $id = (string) $template->getId();

        $crawler = $client->request('GET', '/admin/competition-template/' . $id . '/edit');
        $form    = $crawler->selectButton('Save changes')->form();
        $form['CompetitionTemplate[allowedTiersJson]'] = '';
        $client->submit($form);
        $em->clear();

        self::assertNull(
            $em->getRepository(CompetitionTemplate::class)->findOneBy(['slug' => self::SLUG])->getAllowedTiers(),
        );

        $this->removeTemplate($em);
    }
}
