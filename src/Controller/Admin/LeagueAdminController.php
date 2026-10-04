<?php

namespace App\Controller\Admin;

use App\Entity\League;
use App\Enum\TrophyColour;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class LeagueAdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private GameConfigRepository   $gameConfigRepository,
        private LeagueRepository       $leagueRepository,
    ) {}

    #[Route('/admin/leagues/{id}/quick-edit', name: 'admin_league_quick_edit', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function quickEdit(Request $request, League $league): Response
    {
        if (!$this->isCsrfTokenValid('league_qe_' . $league->getId(), $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
            }
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

        $trophyImage = $request->request->get('trophyImage');
        $validImages = array_map(fn ($n) => "trophy-$n", range(1, 15));
        $league->setTrophyImage(
            ($trophyImage !== null && $trophyImage !== '' && in_array($trophyImage, $validImages, true))
                ? $trophyImage
                : null
        );

        $trophyColour = $request->request->get('trophyColour');
        $league->setTrophyColour(
            $trophyColour !== null && $trophyColour !== '' ? TrophyColour::tryFrom($trophyColour) : null
        );

        $sponsorCount = $request->request->get('sponsorCount');
        if ($sponsorCount !== null && $sponsorCount !== '') {
            $league->setSponsorCount(max(1, min(20, (int) $sponsorCount)));
        }

        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success'          => true,
                'league'           => [
                    'id'               => (string) $league->getId(),
                    'name'             => $league->getName(),
                    'tier'             => $league->getTier(),
                    'promotionSpots'   => $league->getPromotionSpots(),
                    'tvDeal'           => $league->getTvDeal(),
                    'prizeMoney'       => $league->getPrizeMoney(),
                    'leaguePositionPot'=> $league->getLeaguePositionPot(),
                    'trophyImage'      => $league->getTrophyImage(),
                    'trophyColour'     => $league->getTrophyColour()?->value,
                    'sponsorCount'     => $league->getSponsorCount(),
                ],
            ]);
        }

        $this->addFlash('success', sprintf('League "%s" updated.', $league->getName()));
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_leagues_overview']));
    }

    /**
     * Saves the per-tier League Tier Defaults table (always, onto GameConfig — the single
     * source of truth LeagueService::generateLeaguesForCountry() reads for brand-new
     * leagues) and, for whichever existing League rows were checked in the picker below the
     * form, overwrites those rows with their tier's submitted values right now.
     */
    #[Route('/admin/leagues/tier-defaults/bulk-edit', name: 'admin_league_tier_defaults_bulk_edit', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkEditTierDefaults(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('league_tier_defaults_bulk_edit', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_leagues_overview']));
        }

        $submittedTiers = $request->request->all('tiers');
        $newDefaults = [];
        for ($tier = 1; $tier <= 8; $tier++) {
            $row = $submittedTiers[(string) $tier] ?? [];
            $newDefaults[(string) $tier] = $this->normalizeTierRow($row);
        }

        $config = $this->gameConfigRepository->getConfig();
        $config->setLeagueTierDefaults($newDefaults);
        $this->em->flush();

        $leagueIds = $request->request->all('leagueIds');
        $applied = 0;
        foreach ($leagueIds as $leagueId) {
            $league = $this->leagueRepository->find($leagueId);
            if ($league === null) {
                continue;
            }
            $defaults = $newDefaults[(string) $league->getTier()] ?? null;
            if ($defaults === null) {
                continue;
            }
            $league->setPromotionSpots($defaults['promotionSpots']);
            $league->setTvDeal($defaults['tvDeal']);
            $league->setPrizeMoney($defaults['prizeMoney']);
            $league->setLeaguePositionPot($defaults['leaguePositionPot']);
            $league->setSponsorCount($defaults['sponsorCount']);
            $league->setTrophyImage($defaults['trophyImage']);
            $league->setTrophyColour($defaults['trophyColour'] !== null ? TrophyColour::from($defaults['trophyColour']) : null);
            $applied++;
        }
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'League Tier Defaults saved. Applied to %d existing league(s).',
            $applied,
        ));
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_leagues_overview']));
    }

    /** @return array{promotionSpots:?int,tvDeal:?int,prizeMoney:?int,leaguePositionPot:?int,sponsorCount:int,trophyImage:?string,trophyColour:?string} */
    private function normalizeTierRow(array $row): array
    {
        $validImages = array_map(fn ($n) => "trophy-$n", range(1, 15));
        $trophyImage = $row['trophyImage'] ?? null;
        $trophyColour = $row['trophyColour'] ?? null;

        return [
            'promotionSpots'    => ($row['promotionSpots'] ?? '') !== '' ? (int) $row['promotionSpots'] : null,
            'tvDeal'            => ($row['tvDeal'] ?? '') !== '' ? ((int) $row['tvDeal']) * 100 : null,
            'prizeMoney'        => ($row['prizeMoney'] ?? '') !== '' ? ((int) $row['prizeMoney']) * 100 : null,
            'leaguePositionPot' => ($row['leaguePositionPot'] ?? '') !== '' ? ((int) $row['leaguePositionPot']) * 100 : null,
            'sponsorCount'      => ($row['sponsorCount'] ?? '') !== '' ? max(0, min(20, (int) $row['sponsorCount'])) : 0,
            'trophyImage'       => ($trophyImage !== null && $trophyImage !== '' && in_array($trophyImage, $validImages, true)) ? $trophyImage : null,
            'trophyColour'      => ($trophyColour !== null && $trophyColour !== '' && TrophyColour::tryFrom($trophyColour) !== null) ? $trophyColour : null,
        ];
    }
}
