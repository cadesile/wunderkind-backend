<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Location\Location;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\Location\LocationConfigRepository;
use App\Repository\Location\LocationRepository;
use App\Service\Location\CountryGoLiveService;
use App\Service\Location\CountryReadinessService;
use App\Service\Location\LandPlotProvisioner;
use App\Service\Location\LocationCapacityService;
use App\Service\Location\LocationDashboardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Countries and their land, on one screen.
 *
 * WHY A NEW CONTROLLER rather than an override on LocationCrudController: this is not a list
 * of locations. It is a list of COUNTRIES, each with its readiness, its enrichment position
 * and its land — and the towns themselves are reached from it. The location and land-plot
 * tables stay exactly as they are and are linked to from here.
 *
 * REACHED THROUGH /admin?routeName=…, per src/Controller/Admin/CLAUDE.md: the template
 * extends an @EasyAdmin layout, and the `ea` context is only populated for requests that come
 * in through the dashboard's own route. The menu item does that automatically.
 */
#[IsGranted('ROLE_ADMIN')]
class LocationDashboardController extends AbstractController
{
    public function __construct(
        private readonly CountryProfileRepository $countryProfileRepository,
        private readonly LocationRepository $locationRepository,
        private readonly LocationDashboardService $dashboardService,
        private readonly CountryReadinessService $readinessService,
        private readonly CountryGoLiveService $goLiveService,
        private readonly LandPlotProvisioner $provisioner,
        private readonly LocationCapacityService $capacityService,
        private readonly LocationConfigRepository $locationConfigRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin/locations/dashboard', name: 'admin_locations_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, ?string $country = null): Response
    {
        $countries = $this->dashboardService->countryRows();
        $plots     = $this->dashboardService->plotRows();

        $selected = $this->resolveCountry($request, $country, array_keys($countries));

        // The readiness verdict is seven queries, so it is asked for ONE country — the one on
        // screen. CountryProfileCrudController pays it per row on its index; that is the
        // precedent not to copy onto a page that lists every country there is.
        $verdict = $selected === null ? null : $this->readinessService->evaluate($selected);

        return $this->render('admin/location_dashboard.html.twig', [
            'countries'    => $countries,
            'plots'        => $plots,
            'selected'     => $selected,
            'profile'      => $selected === null ? null : $this->countryProfileRepository->findByCode($selected),
            'verdict'      => $verdict,
            'topTowns'     => $selected === null ? [] : $this->topTowns($selected),
            'minSellable'  => CountryReadinessService::MIN_SELLABLE_LOCATIONS,
            'bmcGroup'     => 'Locations & land',
            'bmcTitle'     => 'Locations',
        ]);
    }

    /**
     * Promote a country to LIVE.
     *
     * CSRF IS PER COUNTRY, not per page. The precedent is league_qe_{id}: a page-wide token
     * scraped from one country's form would otherwise promote another.
     *
     * The readiness check runs again here. The button being enabled is not the check — an
     * operator with a page open from before a town failed enrichment would otherwise promote
     * a country that stopped being ready while they read it.
     */
    #[Route('/admin/locations/dashboard/go-live', name: 'admin_locations_go_live', methods: ['POST'])]
    public function goLive(Request $request): Response
    {
        $code = strtoupper(trim((string) $request->request->get('country', '')));

        if (!$this->isCsrfTokenValid('locations_go_live_' . $code, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen($code);
        }

        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            // A flash, not a 404: the operator is mid-workflow on a screen that still works.
            $this->addFlash('danger', sprintf('No country profile for %s.', $code ?: '(none given)'));

            return $this->backToScreen(null);
        }

        $result = $this->goLiveService->goLive($profile);
        $this->addFlash($result['ok'] ? 'success' : 'danger', $result['message']);

        return $this->backToScreen($code);
    }

    /**
     * Top one town up to the plots its size calls for.
     *
     * UP TO TARGET, NOT ONE MORE, and that is a deliberate narrowing of what the design asked
     * for. LandPlotProvisioner reaps surplus free plots on reclassification and on claim or
     * hold release, so a plot added ABOVE a town's target would be retired again the next time
     * any of those happened — the button would appear to work and then quietly undo itself.
     * Making hand-added plots survive needs a `manual` column on land_plot and a change to the
     * one reap query three services share, which weakens the re-banding guarantee
     * LandPlotProvisioningRulesTest pins. Not worth it for a button.
     *
     * So: the button is offered only while a town is below target, and tops it up.
     */
    #[Route('/admin/locations/dashboard/add-plots', name: 'admin_locations_add_plots', methods: ['POST'])]
    public function addPlots(Request $request): Response
    {
        $id = (string) $request->request->get('location', '');

        if (!$this->isCsrfTokenValid('locations_add_plots_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen(null);
        }

        $location = $id === '' ? null : $this->em->find(Location::class, $id);

        if ($location === null) {
            $this->addFlash('danger', 'That town no longer exists.');

            return $this->backToScreen(null);
        }

        $code  = $location->getCountryCode();
        $added = $this->provisioner->provisionLocation($location);

        $this->addFlash(
            $added > 0 ? 'success' : 'info',
            $added > 0
                ? sprintf('Added %d plot(s) to %s.', $added, $location->getTown())
                : sprintf(
                    '%s already has the plots its size calls for, or is not priced yet.',
                    $location->getTown(),
                ),
        );

        return $this->backToScreen($code);
    }

    /**
     * The five biggest towns, with their capacity and the plot target their size calls for.
     *
     * Two reused queries rather than one bespoke one: the towns, then
     * LocationCapacityService::forLocations() for plots and claims — so these figures come out
     * of the same shape the API serves rather than a second opinion about the same rows.
     *
     * `target` IS WHAT DECIDES WHETHER TOP UP IS OFFERED, and it has to be read from the
     * config rather than assumed. The template used to gate the button on a literal `< 4`,
     * which is only BIG's DEFAULT: a MEDIUM town at its target of 2 showed a button that did
     * nothing, and raising BIG above 4 on the Configuration screen HID the button on exactly
     * the towns that then needed it. Resolved here because plotsForCitySize() is where a
     * hand-edited config row is clamped and defaulted — a second reading of the raw array in
     * Twig would be the copy that drifts.
     *
     * @return list<array{location: Location, capacity: array<string, mixed>, target: int}>
     */
    private function topTowns(string $code): array
    {
        $towns = $this->locationRepository->findTopByPopulation($code, 5);

        if ($towns === []) {
            return [];
        }

        $capacity = $this->capacityService->forLocations(
            array_map(static fn (Location $l): string => (string) $l->getId(), $towns),
        );

        $config = $this->locationConfigRepository->getConfig();

        return array_map(static fn (Location $l): array => [
            'location' => $l,
            // forLocations() omits a location with no plots rather than zero-filling it.
            'capacity' => $capacity[(string) $l->getId()] ?? ['plots' => 0, 'claimed' => 0, 'available' => 0],
            'target'   => $config->plotsForCitySize($l->getCitySize()),
        ], $towns);
    }

    /**
     * Which country the screen is showing.
     *
     * THREE PLACES TO LOOK, and that is not defensive coding. AdminRouterSubscriber FORWARDS
     * rather than redirects, so a routeParams value arrives as a request attribute while the
     * outer URI still reads /admin?routeName=…&routeParams[country]=… — the same resolution
     * LocationEnrichmentController does, and its docblock says it was measured rather than
     * assumed.
     *
     * @param list<string> $known
     */
    private function resolveCountry(Request $request, ?string $country, array $known): ?string
    {
        $raw = $country
            ?? $request->query->get('country')
            ?? ($request->query->all('routeParams')['country'] ?? null);

        $code = \is_string($raw) ? strtoupper(trim($raw)) : '';

        if ($code !== '' && \in_array($code, $known, true)) {
            return $code;
        }

        return $known[0] ?? null;
    }

    private function backToScreen(?string $code): Response
    {
        $params = ['routeName' => 'admin_locations_dashboard'];

        if ($code !== null && $code !== '') {
            $params['routeParams'] = ['country' => $code];
        }

        return $this->redirect($this->generateUrl('admin', $params));
    }
}
