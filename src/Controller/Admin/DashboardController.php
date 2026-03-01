<?php

namespace App\Controller\Admin;

use App\Entity\Academy;
use App\Entity\Agent;
use App\Entity\LeaderboardEntry;
use App\Entity\Player;
use App\Entity\Staff;
use App\Entity\SyncRecord;
use App\Entity\Transfer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(private EntityManagerInterface $em) {}

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Wunderkind Admin')
            ->setFaviconPath('favicon.ico');
    }

    public function index(): Response
    {
        $stats = [
            'users'        => $this->em->getRepository(User::class)->count([]),
            'academies'    => $this->em->getRepository(Academy::class)->count([]),
            'syncs'        => $this->em->getRepository(SyncRecord::class)->count([]),
            'invalidSyncs' => $this->em->getRepository(SyncRecord::class)->count(['isValid' => false]),
        ];

        return $this->render('admin/dashboard.html.twig', ['stats' => $stats]);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Users & Academies');
        yield MenuItem::linkToCrud('Users', 'fa fa-users', User::class);
        yield MenuItem::linkToCrud('Academies', 'fa fa-school', Academy::class);
        yield MenuItem::section('Sync & Leaderboards');
        yield MenuItem::linkToCrud('Sync Records', 'fa fa-sync', SyncRecord::class);
        yield MenuItem::linkToCrud('Leaderboard Entries', 'fa fa-trophy', LeaderboardEntry::class);
        yield MenuItem::section('Roster');
        yield MenuItem::linkToCrud('Players', 'fa fa-running', Player::class);
        yield MenuItem::linkToCrud('Staff', 'fa fa-chalkboard-teacher', Staff::class);
        yield MenuItem::linkToCrud('Transfers', 'fa fa-exchange-alt', Transfer::class);
        yield MenuItem::linkToCrud('Agents', 'fa fa-handshake', Agent::class);
    }
}
