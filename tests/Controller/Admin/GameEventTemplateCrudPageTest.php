<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the event-template admin after the move from nested form types to raw JSON editors.
 *
 * The regression that motivated the change is testSavingWithoutEditingDoesNotAlterImpacts:
 * the old EventImpactsType re-encoded a flat `impacts` array as {"0":…,"1":…} and appended
 * null-filled selection_logic/duration_config/choices stubs, so merely opening a template and
 * pressing save corrupted it. Five production rows were damaged this way.
 */
class GameEventTemplateCrudPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'game-event-crud-test-admin@example.com';
    private const SLUG             = 'crud-page-probe-event';

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

    /** A template using the legacy flat-array impacts shape — the one the old form corrupted. */
    private function seedTemplate(EntityManagerInterface $em): GameEventTemplate
    {
        foreach ($em->getRepository(GameEventTemplate::class)->findBy(['slug' => self::SLUG]) as $stale) {
            $em->remove($stale);
        }
        $em->flush();

        $template = new GameEventTemplate(
            self::SLUG,
            EventCategory::NPC_INTERACTION,
            'Crud Page Probe',
            'A probe involving {player_1}.',
            [
                ['target' => 'player_1.morale', 'delta' => -8],
                ['target' => 'pair.relationship', 'delta' => 5],
            ],
            3,
        );
        $template->setSeverity('minor');
        $template->setFiringConditions(['minSquadMorale' => 40]);
        $em->persist($template);
        $em->flush();

        return $template;
    }

    private function removeTemplate(EntityManagerInterface $em): void
    {
        foreach ($em->getRepository(GameEventTemplate::class)->findBy(['slug' => self::SLUG]) as $stale) {
            $em->remove($stale);
        }
        $em->flush();
    }

    public function testIndexAndEditPagesRender(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $id = (string) $this->seedTemplate($em)->getId();

        $client->request('GET', '/admin/game-event-template');
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/admin/game-event-template/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        // The three JSON columns are code editors bound to the virtual *Json properties,
        // not nested collection widgets.
        foreach (['impactsJson', 'firingConditionsJson', 'chainedEventsJson'] as $property) {
            self::assertCount(
                1,
                $crawler->filter('[name="GameEventTemplate[' . $property . ']"]'),
                sprintf('Expected a single editor bound to %s.', $property),
            );
        }

        // Nested collections are gone — no add/delete controls from the old form types.
        self::assertCount(0, $crawler->filter('[name*="[stat_changes]"]'));
        self::assertCount(0, $crawler->filter('[name*="[selection_logic]"]'));

        $this->removeTemplate($em);
    }

    /** The corruption regression: an untouched save must be a no-op. */
    public function testSavingWithoutEditingDoesNotAlterImpacts(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $template = $this->seedTemplate($em);
        $id       = (string) $template->getId();
        $before   = $template->getImpacts();

        $crawler = $client->request('GET', '/admin/game-event-template/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Save changes')->form());
        $em->clear();

        $after = $em->getRepository(GameEventTemplate::class)->findOneBy(['slug' => self::SLUG]);

        self::assertSame($before, $after->getImpacts(), 'Saving an unedited template changed its impacts.');
        self::assertSame(['minSquadMorale' => 40], $after->getFiringConditions());
        self::assertSame('minor', $after->getSeverity(), 'Severity was lost on save.');

        $this->removeTemplate($em);
    }

    /**
     * Malformed JSON must be reported, not swallowed. The previous setters wrote [] or null
     * on a decode failure, silently destroying the stored value.
     */
    public function testInvalidJsonIsRejectedAndLeavesTheStoredValueIntact(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $template = $this->seedTemplate($em);
        $id       = (string) $template->getId();
        $before   = $template->getImpacts();

        $crawler = $client->request('GET', '/admin/game-event-template/' . $id . '/edit');
        $form    = $crawler->selectButton('Save changes')->form();
        $form['GameEventTemplate[impactsJson]'] = '[{"target": "player_1.morale", "delta": -8,}]';

        $crawler = $client->submit($form);
        $em->clear();

        $after = $em->getRepository(GameEventTemplate::class)->findOneBy(['slug' => self::SLUG]);

        self::assertSame($before, $after->getImpacts(), 'Invalid JSON overwrote the stored impacts.');
        self::assertStringContainsString('Invalid JSON', $crawler->text(), 'No validation error was shown.');

        // The typed text comes back so the admin can fix it rather than retype it.
        self::assertStringContainsString('"delta": -8,', $crawler->text());

        $this->removeTemplate($em);
    }

    public function testValidJsonIsSaved(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $id = (string) $this->seedTemplate($em)->getId();

        $crawler = $client->request('GET', '/admin/game-event-template/' . $id . '/edit');
        $form    = $crawler->selectButton('Save changes')->form();
        $form['GameEventTemplate[impactsJson]'] = json_encode([
            'stat_changes' => [
                ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 4],
            ],
        ]);
        $form['GameEventTemplate[chainedEventsJson]'] = json_encode([
            ['nextEventSlug' => 'npc-squad-banter', 'boostMultiplier' => 2.0, 'windowWeeks' => 3],
        ]);
        $client->submit($form);
        $em->clear();

        $after = $em->getRepository(GameEventTemplate::class)->findOneBy(['slug' => self::SLUG]);

        self::assertSame(
            [['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 4]],
            $after->getImpacts()['stat_changes'],
        );
        self::assertSame('npc-squad-banter', $after->getChainedEvents()[0]['nextEventSlug']);

        $this->removeTemplate($em);
    }

    /** Clearing the field means "unconditional", which must survive as SQL NULL. */
    public function testEmptyFiringConditionsBecomesNull(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $id = (string) $this->seedTemplate($em)->getId();

        $crawler = $client->request('GET', '/admin/game-event-template/' . $id . '/edit');
        $form    = $crawler->selectButton('Save changes')->form();
        $form['GameEventTemplate[firingConditionsJson]'] = '';
        $client->submit($form);
        $em->clear();

        self::assertNull(
            $em->getRepository(GameEventTemplate::class)->findOneBy(['slug' => self::SLUG])->getFiringConditions(),
        );

        $this->removeTemplate($em);
    }
}
