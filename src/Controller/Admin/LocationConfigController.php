<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Location\LocationConfig;
use App\Enum\CitySize;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\Location\LocationConfigRepository;
use App\Service\Location\LandPlotProvisioner;
use App\Service\Location\LocationCapacityService;
use App\Service\Location\LocationClassificationService;
use App\Service\Location\LocationSeedService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings for the land economy. Plots per city size today; more over time.
 *
 * ONE PAGE FOR A GROWING SET, which is why it is a bespoke dashboard route rather than an
 * EasyAdmin CRUD on LocationConfig. A CRUD screen would render a json column as a textarea and
 * an operator would be editing raw JSON; and the page needs to show the CONSEQUENCE of a change
 * — how many plots the current estate would gain — which a CRUD form has no place to put. The
 * pool-config screens are the precedent for both decisions.
 *
 * SAVING DOES NOT CREATE PLOTS. Raising a number changes the target; the estate catches up when
 * enrichment prices a town, or when an operator runs the top-up explicitly from here. Keeping
 * those two apart means a mistyped figure is a mistyped figure rather than several thousand
 * plot rows, and lowering a number is always safe — provisioning never removes, because a plot
 * can carry a claim and somebody's real money.
 *
 * Every write is POST-only and CSRF-guarded, and every redirect goes through the `admin` entry
 * point per src/Controller/Admin/CLAUDE.md.
 */
#[IsGranted('ROLE_ADMIN')]
class LocationConfigController extends AbstractController
{
    public function __construct(
        private readonly LocationConfigRepository $configRepository,
        private readonly LandPlotProvisioner $provisioner,
        private readonly LocationSeedService $seedService,
        private readonly LocationClassificationService $classificationService,
        private readonly CountryProfileRepository $countryProfileRepository,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin/locations/config', name: 'admin_location_config', methods: ['GET'])]
    public function overview(): Response
    {
        $config = $this->configRepository->getConfig();

        return $this->render('admin/location_config.html.twig', [
            'config'        => $config,
            'citySizes'     => CitySize::cases(),
            'plots'         => $config->getPlotsByCitySize(),
            'defaults'      => LocationConfig::DEFAULT_PLOTS_BY_CITY_SIZE,
            'minPlots'      => LocationConfig::MIN_PLOTS,
            'maxPlots'      => LocationConfig::MAX_PLOTS,
            'npcPlotCap'    => LocationCapacityService::NPC_PLOT_CAP,
            // What the setting currently means for the estate, so a number is not changed blind.
            'estate'        => $this->estateSummary(),
            // The size bands, and what they currently work out to.
            'bigShare'      => $config->getBigSharePercent(),
            'mediumShare'   => $config->getMediumSharePercent(),
            'smallShare'    => $config->getSmallSharePercent(),
            'minShare'      => LocationConfig::MIN_SHARE_PERCENT,
            'maxShare'      => LocationConfig::MAX_SHARE_PERCENT,
            // One step per country, which is the unit reclassifyCountry() works in. Only
            // countries with priced towns appear: the rest have nothing to rank.
            'reclassifyPlan' => $this->reclassifyPlan(),
            'shortfall'     => $this->shortfall($config),
            'seedable'      => array_map(
                static fn (\App\Enum\Country $c): string => $c->value,
                $this->seedService->seedableCountries(),
            ),
        ]);
    }

    /**
     * Save the plot counts.
     *
     * Clamping and defaulting live on the entity, not here, so the CLI and any future caller
     * get the same guarantees as this form rather than trusting its `min`/`max` attributes —
     * which a client can simply not send.
     */
    #[Route('/admin/locations/config/plots', name: 'admin_location_config_save_plots', methods: ['POST'])]
    public function savePlots(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('location_config_plots', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen();
        }

        $config = $this->configRepository->getConfig();
        $before = $config->getPlotsByCitySize();

        $submitted = [];

        foreach (CitySize::cases() as $size) {
            $submitted[$size->value] = $request->request->get('plots_' . $size->value);
        }

        $config->setPlotsByCitySize($submitted);
        $this->em->flush();

        $after   = $config->getPlotsByCitySize();
        $changes = [];

        foreach ($after as $size => $value) {
            if (($before[$size] ?? null) !== $value) {
                $changes[] = \sprintf('%s %d → %d', $size, $before[$size] ?? 0, $value);
            }
        }

        if ($changes === []) {
            $this->addFlash('info', 'Nothing changed.');

            return $this->backToScreen();
        }

        $this->addFlash('success', \sprintf('Saved: %s.', implode(', ', $changes)));

        // Said plainly, because the number on screen and the estate on disk are now different
        // things and an operator who assumes otherwise will wonder why nothing happened.
        $this->addFlash('info', 'Existing towns are unchanged. Priced towns gain plots when you '
            . 'run the top-up below; lowering a figure never removes a plot.');

        return $this->backToScreen();
    }

    /**
     * Bring the existing estate up to the saved targets.
     *
     * Deliberately a separate, explicit action rather than something saving does for you — see
     * the class docblock. Only priced towns are touched; an unpriced one stays at zero plots
     * until enrichment values it.
     */
    /**
     * Save the BIG / SMALL shares.
     *
     * Its own form rather than part of the plot one: these change what every town IS, where the
     * plot counts only change how much land a town of that size carries. Saving them does NOT
     * reclassify — the bands on disk and the bands in the database are allowed to differ until
     * somebody runs the recalculation and watches it happen.
     */
    #[Route('/admin/locations/config/bands', name: 'admin_location_config_save_bands', methods: ['POST'])]
    public function saveBands(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('location_config_bands', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen();
        }

        $config = $this->configRepository->getConfig();
        $config->setShares(
            (int) $request->request->get('bigSharePercent', LocationConfig::DEFAULT_BIG_SHARE_PERCENT),
            (int) $request->request->get('smallSharePercent', LocationConfig::DEFAULT_SMALL_SHARE_PERCENT),
        );
        $this->em->flush();

        $this->addFlash('success', \sprintf(
            'City size bands saved — %d%% big, %d%% medium, %d%% small. Run the recalculation to apply them.',
            $config->getBigSharePercent(),
            $config->getMediumSharePercent(),
            $config->getSmallSharePercent(),
        ));

        return $this->backToScreen();
    }

    /**
     * Reclassify ONE country, for the queue on the config screen.
     *
     * ONE COUNTRY PER REQUEST, because that is the unit the split is defined over: a town is
     * BIG because it is in the top share of ITS country, so a country is the smallest piece of
     * work that produces a correct answer. It is also what keeps the request short enough to
     * survive a proxy, which is the lesson the town-pull batching already learned the hard way.
     *
     * The same JSON shape the pool generate-chunk endpoints return, so the shared
     * BMC.runJobs() runner drives this without knowing anything about locations.
     */
    #[Route('/admin/locations/config/reclassify-chunk', name: 'admin_location_config_reclassify_chunk', methods: ['POST'])]
    public function reclassifyChunk(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('location_config_reclassify', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $code = strtoupper(trim((string) $request->request->get('country', '')));

        if ($code === '') {
            return $this->json(['success' => false, 'message' => 'No country given.'], 400);
        }

        try {
            $distribution = $this->classificationService->reclassifyCountry($code);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        // An empty distribution means no priced towns, which is "nothing to do" rather than a
        // fault — the same `skipped` the pool endpoints use for a pool already at target.
        if ($distribution === []) {
            return $this->json([
                'success' => true,
                'skipped' => true,
                'count'   => 0,
                'message' => 'No priced towns to rank.',
            ]);
        }

        $total = array_sum($distribution);

        return $this->json([
            'success'      => true,
            'count'        => $total,
            'distribution' => $distribution,
            'message'      => \sprintf(
                '%d town(s): %d big, %d medium, %d small.',
                $total,
                $distribution[CitySize::BIG->value] ?? 0,
                $distribution[CitySize::MEDIUM->value] ?? 0,
                $distribution[CitySize::SMALL->value] ?? 0,
            ),
        ]);
    }

    /**
     * The countries worth reclassifying, with what each currently holds.
     *
     * Only countries with at least one priced town: classification ranks on population and
     * findForClassification() takes only towns that have one, so a country without any would
     * be a step that always reports nothing.
     *
     * @return list<array{code: string, name: string, towns: int}>
     */
    private function reclassifyPlan(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT l.country_code AS code, COUNT(*) AS towns
               FROM location l
              WHERE l.status = 'active' AND l.population IS NOT NULL AND l.population > 0
              GROUP BY l.country_code
              ORDER BY l.country_code",
        );

        $names = $this->countryProfileRepository->nameMap();

        return array_map(static fn (array $r): array => [
            'code'  => (string) $r['code'],
            'name'  => $names[(string) $r['code']] ?? (string) $r['code'],
            'towns' => (int) $r['towns'],
        ], $rows);
    }

    #[Route('/admin/locations/config/provision', name: 'admin_location_config_provision', methods: ['POST'])]
    public function provision(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('location_config_provision', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen();
        }

        $raw     = $request->request->get('country');
        $country = \is_string($raw) && $raw !== '' ? strtoupper(trim($raw)) : null;
        $dryRun  = $request->request->getBoolean('dry_run');

        $codes = $country === null
            ? array_map(static fn (\App\Enum\Country $c): string => $c->value, $this->seedService->seedableCountries())
            : [$country];

        $locations  = 0;
        $plotsAdded = 0;

        foreach ($codes as $code) {
            $result      = $this->provisioner->provisionCountry($code, $dryRun);
            $locations  += $result['locations'];
            $plotsAdded += $result['plotsAdded'];
        }

        if ($plotsAdded === 0) {
            $this->addFlash('info', \sprintf(
                'Nothing to add for %s — every priced town already has the plots its size calls for.',
                $country ?? 'any country',
            ));

            return $this->backToScreen();
        }

        $this->addFlash(
            $dryRun ? 'info' : 'success',
            \sprintf(
                '%s %d plot(s) across %d town(s) in %s.',
                $dryRun ? 'Would add' : 'Added',
                $plotsAdded,
                $locations,
                $country ?? 'all countries',
            ),
        );

        return $this->backToScreen();
    }

    /**
     * Plots and towns as they stand, by size.
     *
     * One query. Unpriced towns are counted separately rather than folded in, because they are
     * the explanation for most of the gap between a target and reality — and an operator
     * looking at "0 plots" needs to know whether that is a misconfiguration or simply a
     * catalogue awaiting enrichment.
     *
     * @return array<string, array{towns: int, priced: int, plots: int}>
     */
    private function estateSummary(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT l.city_size,
                    COUNT(*) AS towns,
                    COUNT(*) FILTER (WHERE l.lat IS NOT NULL AND l.lng IS NOT NULL AND l.land_pence IS NOT NULL) AS priced,
                    COALESCE(SUM(p.plots), 0) AS plots
               FROM location l
               LEFT JOIN (SELECT location_id, COUNT(*) AS plots FROM land_plot GROUP BY location_id) p
                      ON p.location_id = l.id
              WHERE l.status = 'active'
              GROUP BY l.city_size",
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['city_size']] = [
                'towns'  => (int) $row['towns'],
                'priced' => (int) $row['priced'],
                'plots'  => (int) $row['plots'],
            ];
        }

        return $out;
    }

    /**
     * How many plots the saved targets would add if the top-up ran now.
     *
     * Counted the way the provisioner counts — per town, against that town's existing plots,
     * priced towns only — rather than as (target × towns) − plots, which would be wrong for any
     * town already above its target and would make lowering a figure look like it removes
     * plots when nothing ever does.
     */
    private function shortfall(LocationConfig $config): int
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT l.city_size, COALESCE(p.plots, 0) AS plots
               FROM location l
               LEFT JOIN (SELECT location_id, COUNT(*) AS plots FROM land_plot GROUP BY location_id) p
                      ON p.location_id = l.id
              WHERE l.status = 'active'
                AND l.lat IS NOT NULL AND l.lng IS NOT NULL AND l.land_pence IS NOT NULL",
        );

        $missing = 0;

        foreach ($rows as $row) {
            $size = CitySize::tryFrom((string) $row['city_size']);

            if ($size === null) {
                continue;
            }

            $missing += max(0, $config->plotsForCitySize($size) - (int) $row['plots']);
        }

        return $missing;
    }

    private function backToScreen(): Response
    {
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_config']));
    }
}
