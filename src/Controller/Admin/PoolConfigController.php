<?php

namespace App\Controller\Admin;

use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Repository\LeagueRepository;
use App\Repository\PoolConfigRepository;
use App\Service\Admin\NationalityPoolCounts;
use App\Service\MarketPoolService;
use App\Service\NameGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PoolConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PoolConfigRepository $poolConfigRepository,
        private readonly MarketPoolService $marketPoolService,
        private readonly NationalityPoolCounts $nationalityCounts,
        private readonly LeagueRepository $leagueRepository,
    ) {}

    // ── Player Pool ──────────────────────────────────────────────────────

    #[Route('/admin/player-pool-config', name: 'admin_player_pool_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function playerPoolConfig(): Response
    {
        return $this->render('admin/pool_player_config.html.twig', [
            'config'        => $this->poolConfigRepository->getConfig(),
            'poolCounts'    => $this->playerCounts(),
            // Kept: the flat demonym list the old template's chip row reads. Harmless
            // alongside the grouped rows below and one less thing to break in one go.
            'nationalities' => NameGeneratorService::nationalities(),
            // The picker: nationalities grouped into the seven headings, each with a count.
            'nationalityGroups' => $this->nationalityCounts->forPlayers(),
            'bmcGroup'      => 'Config',
            'bmcTitle'      => 'Player pool',
        ]);
    }

    #[Route('/admin/player-pool-config/save', name: 'admin_player_pool_config_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function savePlayerPoolConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_player_pool_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_player_pool_config']));
        }

        $config = $this->poolConfigRepository->getConfig();

        // Player generation
        $config->setPlayerAgeMin((int) $request->request->get('playerAgeMin', 12));
        $config->setPlayerAgeMax((int) $request->request->get('playerAgeMax', 13));
        $config->setPlayerFirstTeamAgeMin((int) $request->request->get('playerFirstTeamAgeMin', 19));
        $config->setPlayerJuniorAbilityCeilingAge((int) $request->request->get('playerJuniorAbilityCeilingAge', 16));
        $config->setPlayerJuniorAbilityCeilingPercent((int) $request->request->get('playerJuniorAbilityCeilingPercent', 10));
        $config->setPlayerYouthAbilityCeilingAge((int) $request->request->get('playerYouthAbilityCeilingAge', 20));
        $config->setPlayerYouthAbilityCeilingPercent((int) $request->request->get('playerYouthAbilityCeilingPercent', 20));
        $config->setYouthHomeNationalityPercentLocal((int) $request->request->get('youthHomeNationalityPercentLocal', 95));
        $config->setYouthHomeNationalityPercentRegional((int) $request->request->get('youthHomeNationalityPercentRegional', 85));
        $config->setYouthHomeNationalityPercentNational((int) $request->request->get('youthHomeNationalityPercentNational', 70));
        $config->setYouthHomeNationalityPercentElite((int) $request->request->get('youthHomeNationalityPercentElite', 45));
        $config->setPlayerPotentialMin((int) $request->request->get('playerPotentialMin', 40));
        $config->setPlayerPotentialMax((int) $request->request->get('playerPotentialMax', 80));
        $config->setPlayerPotentialMean((int) $request->request->get('playerPotentialMean', 60));
        $config->setPlayerAgentChancePercent((int) $request->request->get('playerAgentChancePercent', 40));
        $config->setPlayerHeightMin((int) $request->request->get('playerHeightMin', 145));
        $config->setPlayerHeightMax((int) $request->request->get('playerHeightMax', 160));
        $config->setPlayerWeightMin((int) $request->request->get('playerWeightMin', 38));
        $config->setPlayerWeightMax((int) $request->request->get('playerWeightMax', 55));
        $config->setPersonalityTraitMin((int) $request->request->get('personalityTraitMin', 30));
        $config->setPersonalityTraitMax((int) $request->request->get('personalityTraitMax', 70));

        // Position weighting
        $config->setPositionWeightGk((int) $request->request->get('positionWeightGk', 8));
        $config->setPositionWeightDef((int) $request->request->get('positionWeightDef', 30));
        $config->setPositionWeightMid((int) $request->request->get('positionWeightMid', 38));
        $config->setPositionWeightAtt((int) $request->request->get('positionWeightAtt', 24));

        // Pool target
        $config->setPlayerPoolTarget((int) $request->request->get('playerPoolTarget', 50));

        $this->em->flush();

        $this->addFlash('success', 'Player pool rules saved');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_player_pool_config']));
    }

    #[Route('/admin/player-pool-config/generate-chunk', name: 'admin_player_pool_generate_chunk', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generatePlayerPoolChunk(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('generate_player_pool', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $type        = $request->request->get('type', '');
        $nationality = $request->request->getString('nationality') ?: null;
        $mode        = $request->request->get('mode', 'force');
        $cfg         = $this->poolConfigRepository->getConfig();
        $conn        = $this->em->getConnection();

        $target = match ($type) {
            'players' => $cfg->getPlayerPoolTarget(),
            default   => 0,
        };

        $count = $target;
        if ($mode === 'replenish') {
            $current = match ($type) {
                'players' => (int) $conn->fetchOne("SELECT COUNT(*) FROM player WHERE recruitment_source = 'youth_intake'"),
                default   => 0,
            };
            $count = max(0, $target - $current);
        }

        if ($count <= 0) {
            return $this->json(['success' => true, 'count' => 0, 'message' => 'Already at target.', 'skipped' => true]);
        }

        try {
            match ($type) {
                'players' => $this->marketPoolService->generatePlayers($count, RecruitmentSource::YOUTH_INTAKE, $nationality),
                default   => throw new \InvalidArgumentException("Unknown pool type: {$type}"),
            };
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return $this->json(['success' => true, 'count' => $count, 'message' => "{$count} generated."]);
    }

    #[Route('/admin/player-pool-config/counts', name: 'admin_player_pool_counts', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function playerPoolCountsJson(): JsonResponse
    {
        // ADDITIVE. `players` keeps its name and meaning; `byNationality` is new, so the
        // picker can redraw its per-option counts after a generate run without a reload.
        // Nothing reading the old shape has to change.
        return $this->json($this->playerCounts() + [
            'byNationality' => $this->nationalityCounts->forPlayers(),
        ]);
    }

    #[Route('/admin/player-pool-config/clear', name: 'admin_player_pool_clear', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clearPlayerPool(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('clear_player_pool', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_player_pool_config']));
        }

        $conn = $this->em->getConnection();

        // Delete guardians referencing players first (FK constraint)
        $conn->executeStatement('DELETE FROM guardian WHERE player_id IN (SELECT id FROM player)');
        $players = $conn->executeStatement('DELETE FROM player');

        $this->addFlash('success', "Player pool cleared — {$players} players removed.");
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_player_pool_config']));
    }

    /** @return array{players: int} */
    private function playerCounts(): array
    {
        $conn = $this->em->getConnection();
        return [
            'players' => (int) $conn->fetchOne("SELECT COUNT(*) FROM player WHERE recruitment_source = 'youth_intake'"),
        ];
    }

    // ── Staff Pool (roles + scouts) ──────────────────────────────────────

    #[Route('/admin/staff-pool-config', name: 'admin_staff_pool_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function staffPoolConfig(): Response
    {
        return $this->render('admin/pool_staff_config.html.twig', [
            'config'        => $this->poolConfigRepository->getConfig(),
            'poolCounts'    => $this->staffCounts(),
            'nationalities' => NameGeneratorService::nationalities(),
            // The STAFF ABILITY BANDS grid, A25. Built from the countries that actually have
            // League rows and from each one's real tiers, exactly as
            // DashboardController::starterConfig() builds the player grid beside it — so a
            // 4-tier country shows four inputs and not eight, and a country with no pyramid
            // shows none at all. See saveStaffPoolConfig() for why that makes the save a MERGE.
            'dynamicLeagueTiers' => $this->leagueTiers(),
            // Staff and scouts summed, because a job is role × nationality and this list is
            // the nationality half. The per-role split the tiles need is staffByRole().
            'nationalityGroups' => $this->nationalityCounts->forStaff(),
            'countsByType'      => $this->nationalityCounts->staffByRole(),
            'bmcGroup'          => 'Config',
            'bmcTitle'          => 'Staff pool',
        ]);
    }

    #[Route('/admin/staff-pool-config/save', name: 'admin_staff_pool_config_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveStaffPoolConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_staff_pool_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_staff_pool_config']));
        }

        $config = $this->poolConfigRepository->getConfig();

        // Coach generation (ranges apply to all 5 staff roles)
        $config->setCoachAgeMin((int) $request->request->get('coachAgeMin', 28));
        $config->setCoachAgeMax((int) $request->request->get('coachAgeMax', 60));
        // ⚠️ **THE UNTIERED PAIR ONLY, SINCE A25.** These two band the shared hire-market pool
        // and nothing else; a club's staff come from the per-country/per-tier grid handled
        // below. Not dead config, for the same reason `scoutWageMin/Max` is not — see the note
        // on that pair further down, and PoolConfig::$coachAbilityMin's docblock.
        $config->setCoachAbilityMin((int) $request->request->get('coachAbilityMin', 40));
        $config->setCoachAbilityMax((int) $request->request->get('coachAbilityMax', 75));

        // Scout generation
        $config->setScoutAgeMin((int) $request->request->get('scoutAgeMin', 28));
        $config->setScoutAgeMax((int) $request->request->get('scoutAgeMax', 40));
        $config->setScoutExperienceMin((int) $request->request->get('scoutExperienceMin', 0));
        $config->setScoutExperienceMax((int) $request->request->get('scoutExperienceMax', 10));
        // Weekly wage range in pence, interpolated across a scout's ABILITY by
        // ScoutWageCalculator (it was `experience` until A24, 2026-11-20 — owner ruling).
        // Changing it does NOT reprice existing scouts — run
        // `app:backfill-scout-wages --force` for that.
        //
        // ⚠️ **SINCE A24 THIS PAIR PRICES ONLY THE SHARED HIRE-MARKET POOL.** A scout at a club
        // is priced on the device from `GameConfig::$wageMultiplierTiers[].staffMultiplier`
        // against that club's own tier, exactly as a coach is — so editing these two numbers no
        // longer moves what the player's club pays its own scouts, only what the hire market
        // asks. A24's brief predicted this pair would become dead config; it did not, because the
        // pool is one row served to every country and so has no tier to be banded against. The
        // same is true of a market COACH, whose `weeklySalary` the app also maps straight
        // through. Recorded in a24-wage-bands-report.md.
        //
        // POUNDS IN, PENCE OUT. The screen edits pounds per week, because 2500 meaning £25 is
        // a trap an operator falls into exactly once. The redesigned form posts
        // scoutWagePounds*, which is multiplied up here; the older scoutWage* fields are still
        // read, in pence, so anything posting the previous shape keeps working.
        $config->setScoutWageMin($this->wagePence($request, 'scoutWagePoundsMin', 'scoutWageMin', 2500));
        $config->setScoutWageMax($this->wagePence($request, 'scoutWagePoundsMax', 'scoutWageMax', 7000));
        $config->setScoutJudgementMin((int) $request->request->get('scoutJudgementMin', 40));
        $config->setScoutJudgementMax((int) $request->request->get('scoutJudgementMax', 80));

        // Pool targets
        $config->setCoachPoolTarget((int) $request->request->get('coachPoolTarget', 10));
        $config->setManagerPoolTarget((int) $request->request->get('managerPoolTarget', 5));
        $config->setDirectorOfFootballPoolTarget((int) $request->request->get('directorOfFootballPoolTarget', 2));
        $config->setFacilityManagerPoolTarget((int) $request->request->get('facilityManagerPoolTarget', 3));
        $config->setChairmanPoolTarget((int) $request->request->get('chairmanPoolTarget', 2));
        $config->setScoutPoolTarget((int) $request->request->get('scoutPoolTarget', 5));

        // ── STAFF ABILITY BANDS, per country and per tier (A25, 2026-11-20) ──────────────
        //
        // Submitted as staffRanges[EN][3][min]. Clamped to 1-100 and ordered here as well as
        // in StaffAbilityRangeService, because this is the surface a transposed pair is typed
        // on and the stored row should not hold one in the first place.
        //
        // MERGED OVER WHAT IS STORED, NOT WRITTEN IN PLACE OF IT — the identical trap
        // DashboardController::saveStarterConfig() documents for the player grid. The grid is
        // built from countries that already have League rows, so a country without a pyramid
        // has no inputs on this page at all, and replacing the whole column would therefore
        // DELETE its band silently on any save of this form for any reason — undoing the
        // locations dashboard's "Seed defaults" button. A country absent from the form keeps
        // whatever it has; one present is replaced.
        $staffInput  = $request->request->all('staffRanges') ?: [];
        $staffRanges = [];
        foreach ($staffInput as $country => $tiers) {
            $code = strtoupper(trim((string) $country));
            if ($code === '' || !\is_array($tiers)) {
                continue;
            }
            $band = [];
            foreach ($tiers as $tier => $bounds) {
                $min = (int) ($bounds['min'] ?? 0);
                $max = (int) ($bounds['max'] ?? 0);
                // A BLANK PAIR IS "UNAUTHORED", NOT ZERO. The inputs allow blank (data-blank),
                // and a zeroed row would generate ability-0 staff rather than fall through to
                // the defaults, so an empty pair is dropped and the tier simply goes
                // un-authored — StaffAbilityRangeService::resolve() reports that in its source.
                if ($min <= 0 && $max <= 0) {
                    continue;
                }
                $band[(string) (int) $tier] = [
                    'min' => max(1, min(100, min($min ?: $max, $max ?: $min))),
                    'max' => max(1, min(100, max($min, $max))),
                ];
            }
            if ($band !== []) {
                uksort($band, static fn ($a, $b): int => (int) $a <=> (int) $b);
                $staffRanges[$code] = $band;
            }
        }
        $staffRanges = array_replace($config->getStaffAbilityRanges(), $staffRanges);

        $this->em->flush();

        // Written with a raw UPDATE for the same reason the player grid is: Doctrine's JSON
        // change-detection can silently skip an update when the stored data has mixed
        // string/int types, which a hand-edited grid routinely produces.
        $this->em->getConnection()->executeStatement(
            'UPDATE pool_config SET staff_ability_ranges = :val WHERE id = :id',
            ['val' => json_encode($staffRanges), 'id' => $config->getId()]
        );

        $this->addFlash('success', 'Staff pool rules saved');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_staff_pool_config']));
    }

    /**
     * Countries that have a pyramid, each with its real tiers, sorted.
     *
     * The same derivation DashboardController::starterConfig() makes for the player ability
     * grid. Deliberately NOT a club or league COUNT and not MAX_TIERS: the live row has SC at
     * four tiers and the other 25 countries at eight, and padding SC to eight would author
     * four rows for divisions that do not exist.
     *
     * @return array<string, int[]>
     */
    private function leagueTiers(): array
    {
        $tiers = [];
        foreach ($this->leagueRepository->findAll() as $league) {
            $tiers[$league->getCountry()][] = $league->getTier();
        }
        foreach ($tiers as &$list) {
            $list = array_unique($list);
            sort($list);
        }
        unset($list);
        ksort($tiers);

        return $tiers;
    }

    #[Route('/admin/staff-pool-config/generate-chunk', name: 'admin_staff_pool_generate_chunk', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateStaffPoolChunk(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('generate_staff_pool', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $type        = $request->request->get('type', '');
        $nationality = $request->request->getString('nationality') ?: null;
        $mode        = $request->request->get('mode', 'force');
        $cfg         = $this->poolConfigRepository->getConfig();
        $conn        = $this->em->getConnection();

        $target = match ($type) {
            'coaches'           => $cfg->getCoachPoolTarget(),
            'managers'          => $cfg->getManagerPoolTarget(),
            'directors'         => $cfg->getDirectorOfFootballPoolTarget(),
            'facility_managers' => $cfg->getFacilityManagerPoolTarget(),
            'chairmen'          => $cfg->getChairmanPoolTarget(),
            'scouts'            => $cfg->getScoutPoolTarget(),
            default             => 0,
        };

        $count = $target;
        if ($mode === 'replenish') {
            $current = match ($type) {
                'coaches'           => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'coach'"),
                'managers'          => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'manager'"),
                'directors'         => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'director_of_football'"),
                'facility_managers' => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'facility_manager'"),
                'chairmen'          => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'chairman'"),
                'scouts'            => (int) $conn->fetchOne('SELECT COUNT(*) FROM scout'),
                default             => 0,
            };
            $count = max(0, $target - $current);
        }

        if ($count <= 0) {
            return $this->json(['success' => true, 'count' => 0, 'message' => 'Already at target.', 'skipped' => true]);
        }

        try {
            match ($type) {
                'coaches'           => $this->marketPoolService->generateStaffForRole(StaffRole::COACH, $count, $nationality),
                'managers'          => $this->marketPoolService->generateStaffForRole(StaffRole::MANAGER, $count, $nationality),
                'directors'         => $this->marketPoolService->generateStaffForRole(StaffRole::DIRECTOR_OF_FOOTBALL, $count, $nationality),
                'facility_managers' => $this->marketPoolService->generateStaffForRole(StaffRole::FACILITY_MANAGER, $count, $nationality),
                'chairmen'          => $this->marketPoolService->generateStaffForRole(StaffRole::CHAIRMAN, $count, $nationality),
                'scouts'            => $this->marketPoolService->generateScouts($count, $nationality),
                default             => throw new \InvalidArgumentException("Unknown pool type: {$type}"),
            };
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return $this->json(['success' => true, 'count' => $count, 'message' => "{$count} generated."]);
    }

    #[Route('/admin/staff-pool-config/counts', name: 'admin_staff_pool_counts', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function staffPoolCountsJson(): JsonResponse
    {
        // Additive, as on the player endpoint. `byType` is keyed by the `type` the
        // generate-chunk endpoint takes, not by the staffCoach/staffFacilityMgr keys above —
        // those stay exactly as they are.
        return $this->json($this->staffCounts() + [
            'byNationality' => $this->nationalityCounts->forStaff(),
            'byType'        => $this->nationalityCounts->staffByRole(),
        ]);
    }

    #[Route('/admin/staff-pool-config/clear', name: 'admin_staff_pool_clear', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clearStaffPool(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('clear_staff_pool', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_staff_pool_config']));
        }

        $conn   = $this->em->getConnection();
        $staff  = $conn->executeStatement('DELETE FROM staff');
        $scouts = $conn->executeStatement('DELETE FROM scout');

        $this->addFlash('success', "Staff pool cleared — {$staff} staff, {$scouts} scouts removed.");
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_staff_pool_config']));
    }

    /**
     * A scout wage in pence, from whichever field the form sent.
     *
     * The pounds field wins when it is present — it is what the redesigned screen posts. The
     * pence field is the older shape and is taken verbatim. A blank pounds field is treated as
     * absent rather than as zero, so a half-filled form cannot silently wipe the wage floor.
     */
    private function wagePence(Request $request, string $poundsField, string $penceField, int $default): int
    {
        $pounds = $request->request->get($poundsField);

        if ($pounds !== null && trim((string) $pounds) !== '') {
            return max(0, (int) $pounds) * 100;
        }

        return (int) $request->request->get($penceField, $default);
    }

    /** @return array{staffCoach: int, staffManager: int, staffDirector: int, staffFacilityMgr: int, staffChairman: int, scouts: int} */
    private function staffCounts(): array
    {
        $conn = $this->em->getConnection();
        return [
            'staffCoach'       => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'coach'"),
            'staffManager'     => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'manager'"),
            'staffDirector'    => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'director_of_football'"),
            'staffFacilityMgr' => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'facility_manager'"),
            'staffChairman'    => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'chairman'"),
            'scouts'           => (int) $conn->fetchOne('SELECT COUNT(*) FROM scout'),
        ];
    }

    // ── Investor Pool (agents + sponsors + investors) ───────────────────

    #[Route('/admin/investor-pool-config', name: 'admin_investor_pool_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function investorPoolConfig(): Response
    {
        return $this->render('admin/pool_investor_config.html.twig', [
            'config'     => $this->poolConfigRepository->getConfig(),
            'poolCounts' => $this->investorCounts(),
            // The page strip's crumb and title. bmcGroup is the sidebar group this item
            // sits under, so "ADMIN › CONFIG" matches where the operator just clicked.
            'bmcGroup'   => 'Config',
            'bmcTitle'   => 'Investor pool',
        ]);
    }

    #[Route('/admin/investor-pool-config/save', name: 'admin_investor_pool_config_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveInvestorPoolConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_investor_pool_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_investor_pool_config']));
        }

        $config = $this->poolConfigRepository->getConfig();

        // Agent generation
        $config->setAgentReputationMin((int) $request->request->get('agentReputationMin', 30));
        $config->setAgentReputationMax((int) $request->request->get('agentReputationMax', 70));
        $config->setAgentAgeMin((int) $request->request->get('agentAgeMin', 30));
        $config->setAgentAgeMax((int) $request->request->get('agentAgeMax', 60));

        // Pool targets
        $config->setSponsorPoolTarget((int) $request->request->get('sponsorPoolTarget', 10));
        $config->setInvestorPoolTarget((int) $request->request->get('investorPoolTarget', 5));
        $config->setAgentPoolTarget((int) $request->request->get('agentPoolTarget', 20));

        $this->em->flush();

        $this->addFlash('success', 'Investor pool rules saved');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_investor_pool_config']));
    }

    #[Route('/admin/investor-pool-config/generate-chunk', name: 'admin_investor_pool_generate_chunk', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateInvestorPoolChunk(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('generate_investor_pool', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $type   = $request->request->get('type', '');
        $mode   = $request->request->get('mode', 'force');
        $cfg    = $this->poolConfigRepository->getConfig();
        $conn   = $this->em->getConnection();

        $target = match ($type) {
            'agents'    => $cfg->getAgentPoolTarget(),
            'sponsors'  => $cfg->getSponsorPoolTarget(),
            'investors' => $cfg->getInvestorPoolTarget(),
            default     => 0,
        };

        $count = $target;
        if ($mode === 'replenish') {
            $current = match ($type) {
                'agents'    => (int) $conn->fetchOne('SELECT COUNT(*) FROM agent'),
                'sponsors'  => (int) $conn->fetchOne('SELECT COUNT(*) FROM sponsor WHERE club_id IS NULL'),
                'investors' => (int) $conn->fetchOne('SELECT COUNT(*) FROM investor WHERE club_id IS NULL'),
                default     => 0,
            };
            $count = max(0, $target - $current);
        }

        if ($count <= 0) {
            return $this->json(['success' => true, 'count' => 0, 'message' => 'Already at target.', 'skipped' => true]);
        }

        try {
            match ($type) {
                'agents'    => $this->marketPoolService->generateAgents($count),
                'sponsors'  => $this->marketPoolService->generateSponsors($count),
                'investors' => $this->marketPoolService->generateInvestors($count),
                default     => throw new \InvalidArgumentException("Unknown pool type: {$type}"),
            };
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return $this->json(['success' => true, 'count' => $count, 'message' => "{$count} generated."]);
    }

    #[Route('/admin/investor-pool-config/counts', name: 'admin_investor_pool_counts', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function investorPoolCountsJson(): JsonResponse
    {
        return $this->json($this->investorCounts());
    }

    #[Route('/admin/investor-pool-config/clear', name: 'admin_investor_pool_clear', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clearInvestorPool(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('clear_investor_pool', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_investor_pool_config']));
        }

        $conn      = $this->em->getConnection();
        $investors = $conn->executeStatement('DELETE FROM investor WHERE assigned_at IS NULL');
        $sponsors  = $conn->executeStatement('DELETE FROM sponsor WHERE assigned_at IS NULL');
        $agents    = $conn->executeStatement('DELETE FROM agent');

        $this->addFlash('success', "Investor pool cleared — {$investors} investors, {$sponsors} sponsors, {$agents} agents removed.");
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_investor_pool_config']));
    }

    /** @return array{agents: int, sponsors: int, investors: int} */
    private function investorCounts(): array
    {
        $conn = $this->em->getConnection();
        return [
            'agents'    => (int) $conn->fetchOne('SELECT COUNT(*) FROM agent'),
            'sponsors'  => (int) $conn->fetchOne('SELECT COUNT(*) FROM sponsor WHERE club_id IS NULL'),
            'investors' => (int) $conn->fetchOne('SELECT COUNT(*) FROM investor WHERE club_id IS NULL'),
        ];
    }
}
