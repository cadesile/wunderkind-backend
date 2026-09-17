<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Without __toString(), AssociationField::new('activeCompetition') on this controller's
 * detail page falls back to an opaque "ActiveCompetition #<uuid>" label instead of the
 * competition's actual name — this asserts the real name renders instead. This
 * controller does not itself crash on a missing __toString() (EasyAdmin's association
 * renderer degrades gracefully); the real crash class — an entity used in an
 * AssociationField needing __toString(), per CLAUDE.md — surfaces via
 * ActiveCompetition's own durationOption field instead, covered by
 * tests/Controller/Admin/ActiveCompetitionCrudPageTest.php.
 */
class CompetitionEntrantCrudPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'competition-entrant-crud-test-admin@example.com';
    private const TEST_USER_EMAIL  = 'competition-entrant-crud-test-user@example.com';
    private const SLUG             = 'crud-page-probe-entrant-cup';

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
                foreach ($em->getRepository(CompetitionEntrant::class)->findBy(['activeCompetition' => $instance]) as $entrant) {
                    $em->remove($entrant);
                }
                $em->remove($instance);
            }
            $em->remove($template);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_USER_EMAIL]);
        if ($user !== null) {
            foreach ($em->getRepository(Club::class)->findBy(['user' => $user]) as $club) {
                $em->remove($club);
            }
            $em->remove($user);
        }

        $em->flush();
    }

    public function testDetailPageRendersTheAssociatedActiveCompetitionWithoutCrashing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeFixtures($em);

        $user = new User(self::TEST_USER_EMAIL);
        $user->setPassword('not-used-for-login-here');
        $em->persist($user);

        $club = new Club('Crud Page Probe Entrant Club', $user);
        $em->persist($club);

        $template = new CompetitionTemplate('Crud Page Probe Entrant Cup', self::SLUG, 8, CompetitionDuration::ONE_DAY);
        $em->persist($template);

        $instance = new ActiveCompetition($template);
        $em->persist($instance);

        $entrant = new CompetitionEntrant($instance, $club, 1, ['club' => ['formation' => '4-4-2'], 'players' => []]);
        $em->persist($entrant);
        $em->flush();

        $client->request('GET', '/admin/competition-entrant/' . $entrant->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Crud Page Probe Entrant Cup');
        self::assertSelectorTextContains('body', 'Crud Page Probe Entrant Club');

        $this->removeFixtures($em);
    }
}
