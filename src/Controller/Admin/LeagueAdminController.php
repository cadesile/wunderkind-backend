<?php

namespace App\Controller\Admin;

use App\Entity\League;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class LeagueAdminController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/admin/leagues/{id}/quick-edit', name: 'admin_league_quick_edit', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function quickEdit(Request $request, League $league): Response
    {
        if (!$this->isCsrfTokenValid('league_qe_' . $league->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_leagues_overview']));
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name !== '') {
            $league->setName($name);
        }

        $tier = $request->request->get('tier');
        if ($tier !== null && $tier !== '') {
            $league->setTier(max(1, min(8, (int) $tier)));
        }

        $promotionSpots = $request->request->get('promotionSpots');
        $league->setPromotionSpots(
            ($promotionSpots !== null && $promotionSpots !== '') ? (int) $promotionSpots : null
        );

        $tvDeal = $request->request->get('tvDeal');
        $league->setTvDeal(
            ($tvDeal !== null && $tvDeal !== '') ? ((int) $tvDeal) * 100 : null
        );

        $prizeMoney = $request->request->get('prizeMoney');
        $league->setPrizeMoney(
            ($prizeMoney !== null && $prizeMoney !== '') ? ((int) $prizeMoney) * 100 : null
        );

        $leaguePositionPot = $request->request->get('leaguePositionPot');
        $league->setLeaguePositionPot(
            ($leaguePositionPot !== null && $leaguePositionPot !== '') ? ((int) $leaguePositionPot) * 100 : null
        );

        $this->em->flush();

        $this->addFlash('success', sprintf('League "%s" updated.', $league->getName()));
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_leagues_overview']));
    }
}
