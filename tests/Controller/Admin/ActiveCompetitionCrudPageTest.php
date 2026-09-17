<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Enum\Competition\CompetitionDuration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for a real production crash: durationOption is a backed
 * CompetitionDuration enum with no __toString(), and the index page's TextField
 * tried to render it directly, throwing "could not be converted to string" the
 * moment a real ActiveCompetition row existed (CompetitionTemplateCrudPageTest only
 * exercises CompetitionTemplate's own copy of the field, never this controller).
 */
class ActiveCompetitionCrudPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'active-competition-crud-test-admin@example.com';
    private const SLUG             = 'crud-page-probe-active-cup';

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

    private function removeFixtures(EntityManagerInterface $em): void
    {
        $template = $em->getRepository(CompetitionTemplate::class)->findOneBy(['slug' => self::SLUG]);
        if ($template !== null) {
            foreach ($em->getRepository(ActiveCompetition::class)->findBy(['template' => $template]) as $instance) {
                $em->remove($instance);
            }
            $em->remove($template);
            $em->flush();
        }
    }

    public function testIndexPageRendersAnInstanceWithoutCrashingOnTheDurationEnum(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeFixtures($em);

        $template = new CompetitionTemplate('Crud Page Probe Active Cup', self::SLUG, 8, CompetitionDuration::ONE_DAY);
        $em->persist($template);
        $instance = new ActiveCompetition($template);
        $em->persist($instance);
        $em->flush();

        $client->request('GET', '/admin/active-competition');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Crud Page Probe Active Cup');
        self::assertSelectorTextContains('body', CompetitionDuration::ONE_DAY->name);

        $this->removeFixtures($em);
    }
}
