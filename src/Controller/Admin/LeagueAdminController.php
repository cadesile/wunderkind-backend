<?php

namespace App\Controller\Admin;

use App\Entity\League;
use App\Service\TrophyConfigFactory;
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

        // THROUGH parseMoneyToPence(), WHICH THESE THREE USED TO BYPASS. They cast with a bare
        // (int), and PHP stops casting at the first non-digit — so "1,000" became 1 and saved
        // £10 instead of £1,000, silently, on a field the admin screen formats WITH thousands
        // separators. The bulk form has always stripped them; this one did not, and the two
        // were the same money in the same units on the same screen.
        $league->setTvDeal($this->parseMoneyToPence($request->request->get('tvDeal')));
        $league->setPrizeMoney($this->parseMoneyToPence($request->request->get('prizeMoney')));
        $league->setLeaguePositionPot($this->parseMoneyToPence($request->request->get('leaguePositionPot')));

        $league->setTrophy($this->trophyFromFields([
            'style'           => $request->request->get('trophyStyle'),
            'finish'          => $request->request->get('trophyFinish'),
            'ribbons'         => $request->request->get('trophyRibbons'),
            'ribbonPrimary'   => $request->request->get('trophyRibbonPrimary'),
            'ribbonSecondary' => $request->request->get('trophyRibbonSecondary'),
        ]));

        // 1-20 HERE, 0-20 IN THE BULK FORM, AND THAT DIFFERENCE IS DELIBERATE. A tier default
        // of 0 means "no sponsors at this tier" and has to be expressible; an individual
        // league that exists at all has at least one. Aligning them would either lose the
        // tier-level "none" or let a single league be sponsorless by accident.
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
                    'trophy'           => $league->getTrophy(),
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
            $league->setTrophy($defaults['trophy']);
            $applied++;
        }
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'League Tier Defaults saved. Applied to %d existing league(s).',
            $applied,
        ));
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_leagues_overview']));
    }

    /** @return array{promotionSpots:?int,tvDeal:?int,prizeMoney:?int,leaguePositionPot:?int,sponsorCount:int,trophy:?array} */
    private function normalizeTierRow(array $row): array
    {
        return [
            'promotionSpots'    => ($row['promotionSpots'] ?? '') !== '' ? (int) $row['promotionSpots'] : null,
            'tvDeal'            => $this->parseMoneyToPence($row['tvDeal'] ?? ''),
            'prizeMoney'        => $this->parseMoneyToPence($row['prizeMoney'] ?? ''),
            'leaguePositionPot' => $this->parseMoneyToPence($row['leaguePositionPot'] ?? ''),
            'sponsorCount'      => ($row['sponsorCount'] ?? '') !== '' ? max(0, min(20, (int) $row['sponsorCount'])) : 0,
            'trophy'            => $this->trophyFromFields([
                'style'           => $row['trophyStyle'] ?? null,
                'finish'          => $row['trophyFinish'] ?? null,
                'ribbons'         => $row['trophyRibbons'] ?? null,
                'ribbonPrimary'   => $row['trophyRibbonPrimary'] ?? null,
                'ribbonSecondary' => $row['trophyRibbonSecondary'] ?? null,
            ]),
        ];
    }

    /**
     * The five flat trophy inputs both this screen's forms post, as a validated config.
     *
     * A blank `style` means "no trophy" and yields null — the quick-edit modal and the
     * tier-defaults table both offer that as an explicit choice, and several call sites
     * (the honours board, the tier-defaults table's own shipped defaults) rely on null
     * staying null rather than being defaulted into a visible trophy. Everything else
     * goes through TrophyConfigFactory, so a tampered or stale field falls back per key
     * instead of storing something the sprite cannot draw.
     *
     * @param array<string, mixed> $fields
     * @return array{style:string,finish:string,ribbons:bool,ribbonPrimary:string,ribbonSecondary:string}|null
     */
    private function trophyFromFields(array $fields): ?array
    {
        if (($fields['style'] ?? '') === '' || $fields['style'] === null) {
            return null;
        }

        // An unchecked checkbox posts nothing at all, so an absent key is OFF here —
        // the opposite of TrophyConfigFactory's "absent key keeps the default" rule.
        $fields['ribbons'] = $fields['ribbons'] ?? false;

        return TrophyConfigFactory::validate($fields);
    }

    /** Pounds (comma-formatted, from the tier-defaults table's money inputs) -> pence, or null if blank. */
    /**
     * Pounds as typed — commas and all — to pence, or null for blank.
     *
     * NULLABLE INPUT because quickEdit() reads straight off the request, where a field the
     * form did not send is null rather than ''. The bulk form always sends every key, so it
     * never needed that; sharing the parser is what makes the two agree about "1,000".
     */
    private function parseMoneyToPence(?string $raw): ?int
    {
        $digits = str_replace(',', '', trim((string) $raw));

        return $digits !== '' ? ((int) $digits) * 100 : null;
    }
}
