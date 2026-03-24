<?php

namespace App\Controller\Admin;

use App\Entity\Academy;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Controller\Admin\AcademyCrudController;
use App\Controller\Admin\AgentCrudController;
use App\Controller\Admin\LeaderboardEntryCrudController;
use App\Controller\Admin\PlayerCrudController;
use App\Controller\Admin\StaffCrudController;
use App\Controller\Admin\SyncRecordCrudController;
use App\Controller\Admin\AdminCrudController;
use App\Controller\Admin\InvestorCrudController;
use App\Controller\Admin\ScoutCrudController;
use App\Controller\Admin\SponsorCrudController;
use App\Controller\Admin\UserCrudController;
use App\Controller\Admin\GameEventTemplateCrudController;
use App\Controller\Admin\PlayerArchetypeCrudController;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(private EntityManagerInterface $em) {}

    public function index(): Response
    {
        $conn = $this->em->getConnection();

        $byNationality = $conn->fetchAllAssociative(
            'SELECT nationality, COUNT(*) AS cnt FROM player WHERE academy_id IS NULL GROUP BY nationality ORDER BY cnt DESC LIMIT 15'
        );
        $byPosition = $conn->fetchAllAssociative(
            'SELECT position, COUNT(*) AS cnt FROM player WHERE academy_id IS NULL GROUP BY position ORDER BY cnt DESC'
        );
        $byAge = $conn->fetchAllAssociative(
            'SELECT (YEAR(CURDATE()) - YEAR(date_of_birth)) AS age, COUNT(*) AS cnt FROM player WHERE academy_id IS NULL GROUP BY age ORDER BY age'
        );

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'users'            => $this->em->getRepository(User::class)->count([]),
                'academies'        => $this->em->getRepository(Academy::class)->count([]),
                'syncs'            => $this->em->getRepository(SyncRecord::class)->count([]),
                'invalidSyncs'     => $this->em->getRepository(SyncRecord::class)->count(['isValid' => false]),
                'poolPlayers'      => (int) $conn->fetchOne('SELECT COUNT(*) FROM player WHERE academy_id IS NULL'),
                'assignedPlayers'  => (int) $conn->fetchOne('SELECT COUNT(*) FROM player WHERE academy_id IS NOT NULL'),
                'poolStaff'        => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff WHERE academy_id IS NULL'),
                'assignedStaff'    => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff WHERE academy_id IS NOT NULL'),
            ],
            'charts' => [
                'byNationality' => $byNationality,
                'byPosition'    => $byPosition,
                'byAge'         => $byAge,
            ],
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/images/logo.webp" alt="Wunderkind Factory" style="width:48px;height:48px;image-rendering:pixelated;vertical-align:middle;margin-right:8px;"> Wunderkind')
            ->setFaviconPath('images/logo.webp')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Users & Academies');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        yield MenuItem::linkTo(AcademyCrudController::class, 'Academies', 'fa fa-school');
        yield MenuItem::linkTo(AdminCrudController::class, 'Admins', 'fa fa-user-shield');
        yield MenuItem::section('Sync & Leaderboards');
        yield MenuItem::linkTo(SyncRecordCrudController::class, 'Sync Records', 'fa fa-sync');
        yield MenuItem::linkTo(LeaderboardEntryCrudController::class, 'Leaderboard Entries', 'fa fa-trophy');
        yield MenuItem::section('Roster');
        yield MenuItem::linkTo(PlayerCrudController::class, 'Players', 'fa fa-running');
        yield MenuItem::linkTo(StaffCrudController::class, 'Staff', 'fa fa-chalkboard-teacher');
yield MenuItem::linkTo(AgentCrudController::class, 'Agents', 'fa fa-handshake');
        yield MenuItem::section('Narrative');
        yield MenuItem::linkTo(GameEventTemplateCrudController::class, 'Event Templates', 'fa fa-scroll');
        yield MenuItem::linkTo(PlayerArchetypeCrudController::class, 'Player Archetypes', 'fa fa-masks-theater');
        yield MenuItem::section('System');
        yield MenuItem::linkToRoute('Settings & Tools', 'fa fa-cogs', 'admin_settings');
        yield MenuItem::section('Market');
        yield MenuItem::linkTo(ScoutCrudController::class, 'Scouts', 'fa fa-binoculars');
        yield MenuItem::linkTo(InvestorCrudController::class, 'Investors', 'fa fa-chart-line');
        yield MenuItem::linkTo(SponsorCrudController::class, 'Sponsors', 'fa fa-star');
    }
}
