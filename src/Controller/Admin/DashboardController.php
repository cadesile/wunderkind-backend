<?php

namespace App\Controller\Admin;

use App\Controller\Admin\AcademyCrudController;
use App\Controller\Admin\AgentCrudController;
use App\Controller\Admin\LeaderboardEntryCrudController;
use App\Controller\Admin\PlayerCrudController;
use App\Controller\Admin\StaffCrudController;
use App\Controller\Admin\SyncRecordCrudController;
use App\Controller\Admin\TransferCrudController;
use App\Controller\Admin\AdminCrudController;
use App\Controller\Admin\InvestorCrudController;
use App\Controller\Admin\ScoutCrudController;
use App\Controller\Admin\SponsorCrudController;
use App\Controller\Admin\UserCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
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
        yield MenuItem::linkTo(TransferCrudController::class, 'Transfers', 'fa fa-exchange-alt');
        yield MenuItem::linkTo(AgentCrudController::class, 'Agents', 'fa fa-handshake');
        yield MenuItem::section('Market');
        yield MenuItem::linkTo(ScoutCrudController::class, 'Scouts', 'fa fa-binoculars');
        yield MenuItem::linkTo(InvestorCrudController::class, 'Investors', 'fa fa-chart-line');
        yield MenuItem::linkTo(SponsorCrudController::class, 'Sponsors', 'fa fa-star');
    }
}
