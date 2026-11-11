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
use App\Service\CountryContent\CountryNamingContentProvider;
use App\Service\CountryContent\NamingContentService;
use App\Service\CountryContent\Suggestion\NamingContentUnavailableException;
use App\Service\StarterAbilityRangeService;
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
        private readonly StarterAbilityRangeService $abilityRangeService,
        private readonly CountryNamingContentProvider $namingContent,
        private readonly NamingContentService $namingContentService,
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
            // The CONTENT card's state: each category's effective values and where they came
            // from, so the form can say whether you are editing the built-in list or your own.
            'namingContent' => $this->namingContentFor($selected),
            // isConfigured(), not a live connection check: this screen makes no provider call
            // today and must not start making one on every page load.
            'namingAiReady' => $this->namingContentService->suggesterIsConfigured(),
            'namingLimits'  => [
                'minSuffixes' => \App\Service\CountryContent\NamingContentValidator::MIN_SUFFIXES,
                'minFormats'  => \App\Service\CountryContent\NamingContentValidator::MIN_STADIUM_FORMATS,
                'prestige'    => \App\Service\CountryContent\NamingContentValidator::SUGGESTED_PRESTIGE,
                'generic'     => \App\Service\CountryContent\NamingContentValidator::SUGGESTED_GENERIC,
                'formats'     => \App\Service\CountryContent\NamingContentValidator::SUGGESTED_FORMATS,
            ],
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
     * Give this country the standard eight-tier player ability band.
     *
     * THE BLOCKER USED TO NAME A SHELL COMMAND. "no StarterConfig::$leagueAbilityRanges entry
     * — app:country:bootstrap seeds it" is an admin panel telling an operator to go and find a
     * terminal, for nine lines of work that cost nothing and change no rows but one JSON
     * column. This is that step of the bootstrap, and nothing else of it.
     *
     * NOT REACHABLE FROM THE STARTER CONFIG SCREEN, which is why it has to live here. That
     * screen's grid is built from countries that already have League rows, so a country with
     * no pyramid yet — the state this blocker describes — has no row to type into.
     *
     * CSRF is per country, for the same reason goLive()'s is.
     */
    #[Route('/admin/locations/dashboard/ability-ranges', name: 'admin_locations_seed_ability_ranges', methods: ['POST'])]
    public function seedAbilityRanges(Request $request): Response
    {
        $code = strtoupper(trim((string) $request->request->get('country', '')));

        if (!$this->isCsrfTokenValid('locations_ability_ranges_' . $code, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen($code);
        }

        if ($this->countryProfileRepository->findByCode($code) === null) {
            $this->addFlash('danger', sprintf('No country profile for %s.', $code ?: '(none given)'));

            return $this->backToScreen(null);
        }

        $seeded = $this->abilityRangeService->seedDefaults($code);

        $this->addFlash(
            $seeded ? 'success' : 'info',
            $seeded
                ? sprintf('Seeded the standard eight-tier ability band for %s.', $code)
                : sprintf('%s already has an ability band — nothing was changed.', $code),
        );

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
     * Author this country's club-naming content.
     *
     * THE BLOCKER THIS CLEARS USED TO NEED A DEPLOY. "missing from CountryContentRegistry"
     * pointed an operator at a 2,364-line PHP file; forty-six of the seventy-one Country cases
     * are in that state, so it is the normal condition of a new country rather than an edge.
     *
     * Validated hard before anything is stored — one of these lists is fed to sprintf(), and a
     * stray percent sign typed here would otherwise become a fatal inside club generation, a
     * long way from this form.
     */
    #[Route('/admin/locations/dashboard/content', name: 'admin_locations_save_content', methods: ['POST'])]
    public function saveContent(Request $request): Response
    {
        $code = strtoupper(trim((string) $request->request->get('country', '')));

        if (!$this->isCsrfTokenValid('locations_content_' . $code, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen($code);
        }

        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            $this->addFlash('danger', sprintf('No country profile for %s.', $code ?: '(none given)'));

            return $this->backToScreen(null);
        }

        $input = [];

        foreach (CountryNamingContentProvider::CATEGORIES as $category) {
            $input[$category] = NamingContentService::splitLines((string) $request->request->get($category, ''));
        }

        $verdict = $this->namingContentService->save($profile, $input);

        if ($verdict->isEmpty() && !$verdict->hasRejections()) {
            $this->addFlash('info', sprintf('Nothing changed for %s.', $profile->getName()));
        } elseif (!$verdict->isEmpty()) {
            $this->addFlash('success', sprintf(
                'Saved %s for %s.',
                implode(', ', array_keys($verdict->accepted)),
                $profile->getName(),
            ));
        }

        // Each refusal names its category AND its reason. "Rejected" on its own leaves an
        // operator with nothing to do about it, and this is a form they are standing in front
        // of — the reason is the whole value.
        foreach ($verdict->rejected as $category => $why) {
            $this->addFlash('warning', sprintf('%s was not saved: %s', $category, $why));
        }

        foreach ($verdict->flatNotes() as $note) {
            $this->addFlash('info', $note);
        }

        return $this->backToScreen($code);
    }

    /**
     * Ask the model for this country's naming content.
     *
     * One billed call. POST-only and CSRF-guarded like every other spending trigger in this
     * codebase, and for the same reason: a crawler or a browser prefetch must not be able to
     * spend money.
     */
    #[Route('/admin/locations/dashboard/content/suggest', name: 'admin_locations_suggest_content', methods: ['POST'])]
    public function suggestContent(Request $request): Response
    {
        $code = strtoupper(trim((string) $request->request->get('country', '')));

        if (!$this->isCsrfTokenValid('locations_suggest_content_' . $code, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token — nothing was asked for.');

            return $this->backToScreen($code);
        }

        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            $this->addFlash('danger', sprintf('No country profile for %s.', $code ?: '(none given)'));

            return $this->backToScreen(null);
        }

        try {
            $result = $this->namingContentService->suggest($profile, $request->request->getBoolean('overwrite'));
        } catch (NamingContentUnavailableException $e) {
            // "Could not ask" is the operator's configuration to fix, and must not read like
            // "asked, got something unusable" — which is reported per category below.
            $this->addFlash('danger', $e->getMessage());

            return $this->backToScreen($code);
        }

        /** @var \App\Service\CountryContent\ValidatedNamingContent $verdict */
        $verdict = $result['verdict'];

        // Accepted MINUS skipped: a category the validator was happy with but the overwrite
        // guard held back was not written, and saying it was would be a lie the operator
        // could only catch by reading the fields.
        $written = array_values(array_diff(array_keys($verdict->accepted), $result['skipped']));

        if ($written !== []) {
            $this->addFlash('success', sprintf(
                'Wrote %s for %s. Read them over before going live.',
                implode(', ', $written),
                $profile->getName(),
            ));
        }

        if ($result['skipped'] !== []) {
            $this->addFlash('info', sprintf(
                'Left %s alone — you had already authored them. Tick overwrite to replace them.',
                implode(', ', $result['skipped']),
            ));
        }

        foreach ($verdict->rejected as $category => $why) {
            $this->addFlash('warning', sprintf('%s was not usable: %s', $category, $why));
        }

        if ($written === [] && $verdict->rejected === [] && $result['skipped'] === []) {
            $this->addFlash('warning', sprintf('The model offered nothing usable for %s.', $profile->getName()));
        }

        return $this->backToScreen($code);
    }

    /** Put a country back on the built-in PHP lists. */
    #[Route('/admin/locations/dashboard/content/clear', name: 'admin_locations_clear_content', methods: ['POST'])]
    public function clearContent(Request $request): Response
    {
        $code = strtoupper(trim((string) $request->request->get('country', '')));

        if (!$this->isCsrfTokenValid('locations_clear_content_' . $code, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen($code);
        }

        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            $this->addFlash('danger', sprintf('No country profile for %s.', $code ?: '(none given)'));

            return $this->backToScreen(null);
        }

        $this->namingContentService->clear($profile);
        $this->addFlash('success', sprintf('%s is back on the built-in lists.', $profile->getName()));

        return $this->backToScreen($code);
    }

    /**
     * Each naming category's effective values and source, for the form.
     *
     * @return array<string, array{values: list<string>, source: string}>
     */
    private function namingContentFor(?string $code): array
    {
        $country = $code === null ? null : \App\Enum\Country::tryFrom($code);

        if ($country === null) {
            return [];
        }

        return $this->namingContent->describe($country);
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
