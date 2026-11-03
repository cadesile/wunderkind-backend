<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Admin;
use App\Enum\Country;
use App\Enum\Location\EnrichmentStatus;
use App\Repository\Location\LocationEnrichmentAttemptRepository;
use App\Repository\Location\LocationEnrichmentRunRepository;
use App\Repository\Location\LocationRepository;
use App\Service\Location\Enrichment\EnrichmentSweepInProgressException;
use App\Service\Location\Enrichment\LocationEnricherInterface;
use App\Service\Location\Enrichment\LocationEnrichmentOrchestrator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The enrichment overview, and the three triggers: one location, a selection, everything left.
 *
 * Modelled on the "Close now" panel on LeaderboardAwardDefinitionCrudController — there is no
 * crontab under Lando, so an admin-triggered POST is how a queued job gets exercised by hand.
 * Every write here is POST-only and CSRF-guarded, because each one queues paid API calls and a
 * stray GET (a crawler, a prefetch, a refresh) must not be able to spend money.
 *
 * Every redirect goes through the `admin` entry point per src/Controller/Admin/CLAUDE.md;
 * redirectToRoute() would bypass EasyAdmin's `ea` context and produce the "i18n on null" Twig
 * error.
 */
#[IsGranted('ROLE_ADMIN')]
class LocationEnrichmentController extends AbstractController
{
    public function __construct(
        private readonly LocationEnrichmentOrchestrator      $orchestrator,
        private readonly LocationRepository                  $locationRepository,
        private readonly LocationEnrichmentRunRepository     $runRepository,
        private readonly LocationEnrichmentAttemptRepository $attemptRepository,
        private readonly LocationEnricherInterface           $enricher,
        private readonly Connection                          $connection,
    ) {}

    /**
     * @param string|null $location resolved from EasyAdmin's `routeParams`, which arrive as
     *                              request attributes because AdminRouterSubscriber FORWARDS to
     *                              this route rather than redirecting — so the outer request URI
     *                              still reads /admin?routeName=...&routeParams[location]=... and
     *                              $request->query has no `location` key at all. Measured, not
     *                              assumed. The query-string fallback below keeps the page usable
     *                              when linked to directly.
     */
    #[Route('/admin/locations/enrichment', name: 'admin_location_enrichment', methods: ['GET'])]
    public function overview(Request $request, ?string $location = null): Response
    {
        $country = $request->query->get('country');
        $country = is_string($country) && $country !== '' ? strtoupper($country) : null;

        if ($country === null) {
            $nested = $request->query->all('routeParams');
            $country = isset($nested['country']) && is_string($nested['country']) && $nested['country'] !== ''
                ? strtoupper($nested['country'])
                : null;
        }

        $nested  = $request->query->all('routeParams');
        $focusId = $location
            ?? (is_string($request->query->get('location')) ? $request->query->get('location') : null)
            ?? (isset($nested['location']) && is_string($nested['location']) ? $nested['location'] : null);

        $focus = is_string($focusId) && $focusId !== ''
            ? $this->connection->fetchAssociative(
                'SELECT id, town, country_code, enrichment_status, enrichment_attempts, enrichment_error
                   FROM location WHERE id = :id',
                ['id' => $focusId],
            ) ?: null
            : null;

        return $this->render('admin/location_enrichment.html.twig', [
            'focus'         => $focus,
            'connection'    => $this->enricher->checkConnection(),
            'provider'      => $this->enricher->provider(),
            'model'         => $this->enricher->model(),
            'promptVersion' => $this->enricher->promptVersion(),
            'selectedCountry' => $country,
            'countries'     => $this->countryRows(),
            'totals'        => $this->locationRepository->countByEnrichmentStatus($country),
            'outstanding'   => $this->orchestrator->countOutstanding($country),
            'queueDepth'    => $this->orchestrator->pendingOnQueue(),
            'recentRuns'    => $this->runRepository->findRecent(10),
            'usage'         => $this->attemptRepository->usageSince(new \DateTimeImmutable('-30 days')),
            'reviewQueue'   => $this->reviewQueue($country),
            'statuses'      => EnrichmentStatus::cases(),
        ]);
    }

    /**
     * Enrich one location.
     *
     * POST-ONLY, like the other two triggers, and that is a deliberate reversal. This started
     * as a GET so an EasyAdmin row action could link straight to it — but a GET that spends
     * money is wrong on principle, and the attempt to make it safe with a CSRF token in the
     * query string does not work: Symfony's SameOriginCsrfTokenManager issues double-submit
     * tokens that cannot be validated from a URL parameter. Rather than weaken the check to fit
     * the link, the link now points at the enrichment screen with ?location=<id>, which renders
     * a confirm button that posts here. One extra click, no money-spending GET anywhere.
     */
    #[Route('/admin/locations/enrichment/single', name: 'admin_location_enrich_single', methods: ['POST'])]
    public function enrichSingle(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('location_enrichment_single', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token — nothing was queued.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
        }

        $location = (string) $request->request->get('location');

        if ($location === '') {
            $this->addFlash('warning', 'No location given — nothing was queued.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
        }

        $run = $this->orchestrator->queueSingle($location, $this->currentAdmin());

        if ($run === null) {
            $this->addFlash('warning', 'Nothing queued — that location is already enriched or already in flight.');
        } else {
            $this->addFlash('success', 'Queued one location for enrichment. Drain the enrichment queue to see the result.');
        }

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
    }

    /**
     * Enrich everything still outstanding, for one country or globally.
     *
     * POST-only and CSRF-guarded: on a full sweep this queues 865 billed calls.
     */
    #[Route('/admin/locations/enrichment/sweep', name: 'admin_location_enrich_sweep', methods: ['POST'])]
    public function sweep(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('location_enrichment_sweep', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token — nothing was queued.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
        }

        $country = $request->request->get('country');
        $country = is_string($country) && $country !== '' ? strtoupper($country) : null;

        $statuses = [EnrichmentStatus::PENDING, EnrichmentStatus::FAILED];

        if ($request->request->getBoolean('include_review')) {
            // Opt-in, because re-asking about a row a human has not yet judged hides the thing
            // that needed judging.
            $statuses[] = EnrichmentStatus::NEEDS_REVIEW;
        }

        try {
            $run = $this->orchestrator->queueAllOutstanding($country, $this->currentAdmin(), $statuses);
        } catch (EnrichmentSweepInProgressException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
        }

        if ($run === null) {
            $this->addFlash('warning', 'Nothing outstanding in that scope — nothing queued.');
        } else {
            $this->addFlash('success', sprintf(
                'Queued %d location(s) as run %s. Each one is a paid API call; drain the enrichment queue to process them.',
                $run->getQueued(),
                $run->getId(),
            ));
        }

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
    }

    /** Enrich a hand-picked selection, posted as a list of ids. */
    #[Route('/admin/locations/enrichment/subset', name: 'admin_location_enrich_subset', methods: ['POST'])]
    public function subset(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('location_enrichment_subset', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token — nothing was queued.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
        }

        /** @var list<string> $ids */
        $ids = array_values(array_filter((array) $request->request->all('locations')));

        if ($ids === []) {
            $this->addFlash('warning', 'No locations selected.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
        }

        $run = $this->orchestrator->queueSubset($ids, $this->currentAdmin());

        if ($run === null) {
            $this->addFlash('warning', 'Nothing queued — every selected location is already enriched or in flight.');
        } else {
            $this->addFlash('success', sprintf('Queued %d of %d selected location(s).', $run->getQueued(), count($ids)));
        }

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
    }

    private function currentAdmin(): ?Admin
    {
        $user = $this->getUser();

        return $user instanceof Admin ? $user : null;
    }

    /**
     * Per-country progress, so the overview answers "what is left" at a glance.
     *
     * @return list<array{code: string, name: string, status: string, total: int, counts: array<string, int>}>
     */
    private function countryRows(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.code, c.name, c.status,
                    COUNT(l.id) AS total,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'pending')      AS pending,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'queued')       AS queued,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'enriched')     AS enriched,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'needs_review') AS needs_review,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'failed')       AS failed
               FROM country_profile c
               LEFT JOIN location l ON l.country_code = c.code AND l.status = 'active'
              GROUP BY c.code, c.name, c.status
              ORDER BY c.name",
        );

        return array_map(static fn (array $row): array => [
            'code'   => (string) $row['code'],
            'name'   => (string) $row['name'],
            'status' => (string) $row['status'],
            'total'  => (int) $row['total'],
            'counts' => [
                'pending'      => (int) $row['pending'],
                'queued'       => (int) $row['queued'],
                'enriched'     => (int) $row['enriched'],
                'needs_review' => (int) $row['needs_review'],
                'failed'       => (int) $row['failed'],
            ],
        ], $rows);
    }

    /**
     * The rows a human has to look at: review-flagged and failed.
     *
     * Projected, not hydrated — this list is the operator's work queue and must not pull the
     * attempt table's raw response payloads with it.
     *
     * @return list<array<string, mixed>>
     */
    private function reviewQueue(?string $country): array
    {
        $sql = "SELECT l.id, l.town, l.country_code, l.enrichment_status, l.enrichment_error,
                       l.enrichment_attempts, l.lat, l.land_pence
                  FROM location l
                 WHERE l.status = 'active'
                   AND l.enrichment_status IN ('needs_review', 'failed')";

        $params = [];

        if ($country !== null) {
            $sql .= ' AND l.country_code = :country';
            $params['country'] = $country;
        }

        $sql .= ' ORDER BY l.enrichment_status, l.population DESC NULLS LAST LIMIT 100';

        return $this->connection->fetchAllAssociative($sql, $params);
    }
}
