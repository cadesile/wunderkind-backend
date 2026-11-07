<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Admin;
use App\Enum\Country;
use App\Enum\Location\EnrichmentStatus;
use App\Enum\Location\EnrichmentTrigger;
use App\Repository\Location\LocationEnrichmentAttemptRepository;
use App\Repository\Location\LocationEnrichmentRunRepository;
use App\Repository\Location\LocationRepository;
use App\Repository\Location\TownSuggestionBatchRepository;
use App\Service\Location\Enrichment\EnrichmentSweepInProgressException;
use App\Service\Location\Enrichment\LocationEnricherInterface;
use App\Service\Location\Enrichment\LocationEnrichmentOrchestrator;
use Doctrine\DBAL\Connection;
use App\Service\Location\LocationImportExportService;
use App\Service\Location\LocationSeedService;
use App\Message\Location\SuggestTownsBatchMessage;
use App\Service\Location\Suggestion\TownSuggestionService;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        private readonly LoggerInterface                     $logger,
        private readonly LocationSeedService                 $seedService,
        private readonly TownSuggestionService               $suggestionService,
        private readonly TownSuggestionBatchRepository       $batchRepository,
        private readonly MessageBusInterface                 $messageBus,
        private readonly CacheInterface                      $cache,
    ) {}

    /**
     * The transport batched town pulls are queued on.
     *
     * Must keep matching the `suggestion` queue_name in config/packages/messenger.yaml —
     * the same contract LocationEnrichmentOrchestrator::TRANSPORT_QUEUE and
     * StarterPoolAuditService::TRANSPORT_QUEUE carry, and for the same reason: undrained
     * rows on it are counted to stop a second run stacking on the first.
     */
    public const SUGGESTION_QUEUE = 'suggestion';

    /**
     * How long a provider reachability verdict is reused.
     *
     * checkConnection() is a live round trip to the AI provider, and it used to run on EVERY
     * GET of this page with no cache — so the screen could hang for the client's full 45s
     * timeout before rendering anything, which is its own flavour of the problem this page's
     * queued pull was built to fix. 60s is AdminQueueCounts' window: long enough that a
     * reload costs nothing, short enough that fixing a bad key is visible almost at once.
     */
    private const CONNECTION_CACHE_SECONDS = 60;

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
            'connection'    => $this->cachedConnection(),
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
            // Every country the registry HAS places for, flagged with whether it is already
            // seeded — so the seed picker can offer the unseeded ones first and label the rest
            // honestly rather than looking like it does nothing.
            'seedable'      => $this->seedableRows(),
            // So the Pull towns card can resume its progress poll after a reload. The whole
            // point of queueing is that the operator is no longer pinned to the request, so
            // they will navigate away and come back mid-run.
            'suggestionQueueDepth' => $this->runRepository->countUndeliveredOnQueue(self::SUGGESTION_QUEUE),
            'suggestionRun'        => $this->latestSuggestionRunPayload($country),
            // Rendered into the form rather than hardcoded in Twig, so the card and the service
            // cannot drift on what a batch is or what the cap is.
            'batchSize'            => TownSuggestionService::BATCH_SIZE,
            'defaultMax'           => TownSuggestionService::DEFAULT_MAX,
            'hardMax'              => TownSuggestionService::HARD_MAX,
        ]);
    }

    /**
     * The provider verdict, reused for a minute.
     *
     * Wrapped rather than called directly because it is a synchronous third-party call on a GET:
     * uncached it made every page load wait on the provider, at the mercy of the same proxy
     * timeout as everything else here.
     *
     * @return array{ok: bool, detail: string}
     */
    private function cachedConnection(): array
    {
        /** @var array{ok: bool, detail: string} */
        return $this->cache->get(
            'admin.location_enrichment.connection',
            function (ItemInterface $item): array {
                $item->expiresAfter(self::CONNECTION_CACHE_SECONDS);

                return $this->enricher->checkConnection();
            },
        );
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
     * Seedable countries, each flagged with whether it already has locations.
     *
     * Drives the seed picker. The flag matters: seeding is act-only-when-empty per country, so
     * choosing an already-seeded one does nothing unless "force" is ticked — and a picker that
     * did not say so would look broken rather than careful.
     *
     * @return list<array{code: string, name: string, seeded: bool}>
     */
    private function seedableRows(): array
    {
        $seeded = array_flip(array_map(
            static fn (array $row): string => $row['code'],
            $this->countryRows(),
        ));

        return array_map(
            static fn (\App\Enum\Country $c): array => [
                'code'   => $c->value,
                'name'   => $c->label(),
                'seeded' => isset($seeded[$c->value]),
            ],
            $this->seedService->seedableCountries(),
        );
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


    /**
     * Seed country_profile, location and land_plot rows from CountryContentRegistry.
     *
     * The admin face of app:locations:seed. Both call LocationSeedService and neither holds any
     * seeding logic of its own, so the button and the command cannot drift.
     *
     * SYNCHRONOUS, DELIBERATELY. A full seed is about 1,755 small inserts across 25 countries
     * and finishes in seconds; it touches no external service and spends no money, so there is
     * nothing here worth the complexity of a queue — unlike enrichment, where every message is
     * a billed API call. The country picker also lets an operator do it one country at a time.
     *
     * ACT-ONLY-WHEN-EMPTY per country unless `force` is sent, which only ADDS missing towns and
     * never rewrites an existing row. That restraint is the point: these rows get hand-corrected
     * and then carry real money, so a re-seed must not be able to stomp a correction.
     */
    #[Route('/admin/locations/seed', name: 'admin_locations_seed', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function seed(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('locations_seed', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen();
        }

        $code  = $this->normalisedCountry($request->request->get('country'));
        $force = $request->request->has('force');

        if ($code === null) {
            $countries = $this->seedService->seedableCountries();
        } else {
            try {
                $countries = [$this->seedService->resolveCountry($code)];
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());

                return $this->backToScreen();
            }
        }

        if ($countries === []) {
            $this->addFlash('warning', 'No countries have place data in CountryContentRegistry.');

            return $this->backToScreen();
        }

        try {
            $result = $this->seedService->seedMany($countries, $force ? $code : null);
        } catch (\Throwable $e) {
            $this->logger->error('Location seeding failed', ['exception' => $e]);
            $this->addFlash('danger', 'Seeding failed: ' . $e->getMessage());

            return $this->backToScreen();
        }

        if ($result['locations'] === 0) {
            // The common and correct outcome of pressing it twice. Said plainly so it does not
            // read as a failure.
            $this->addFlash('info', sprintf(
                'Nothing to seed — every registry town is already present in %s.',
                $code ?? 'all ' . count($countries) . ' countries',
            ));

            return $this->backToScreen();
        }

        $this->addFlash('success', sprintf(
            'Seeded %d location(s) and %d plot(s) across %d country/countries.',
            $result['locations'],
            $result['plots'],
            count($countries),
        ));

        // The thing an operator will otherwise be surprised by: seeded towns have no geography
        // at all, because there is none in PHP to seed.
        $this->addFlash('warning', sprintf(
            '%d location(s) have no coordinates and no land value yet. Enrich them below, then '
            . 'run app:locations:rivals. A country stays out of the API until its profile is LIVE.',
            $result['locations'],
        ));

        return $this->backToScreen();
    }

    /**
     * Queue a batched town pull for one country.
     *
     * QUEUED, NOT SYNCHRONOUS, AND THAT IS THE WHOLE POINT OF THIS ACTION. It used to call
     * TownSuggestionService::suggestForCountry() inline: one AI call for up to 300 names, a
     * ~12,000 output-token generation taking 60-180s, behind an nginx that gives up at 60s. The
     * operator saw a failure for work that was often still running and sometimes landed
     * afterwards, with no flash to say so.
     *
     * It now writes a run plus one row per ~50 towns and dispatches a message each, so the
     * request returns at once and progress is real rather than a spinner. Batches also mean a
     * provider failure costs one batch instead of the run.
     *
     * ONE COUNTRY AT A TIME, STILL ENFORCED. "All countries" is refused rather than fanning
     * twenty-five paid runs out of a single click. That also sidesteps the trap in the seed form
     * beside it, where ticking `force` with "All countries" selected does nothing at all,
     * silently: seed() passes `$force ? $code : null`, and `$code` is null.
     *
     * NOW GUARDED AGAINST STACKING, which the synchronous version did not need. It was one call
     * and the docblock said so; a run is now N billed messages, so a second click while the
     * first is draining would double the spend and interleave two sets of batches competing for
     * the same names. Same countUndeliveredOnQueue() idiom the sweep beside it uses.
     *
     * The towns it adds are unpriced and unplaced. Enrichment fills them in lazily, one paid
     * call each, when a player first opens them — which is the design, not a backlog.
     */
    #[Route('/admin/locations/suggest-towns', name: 'admin_locations_suggest_towns', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function suggestTowns(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('locations_suggest_towns', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen();
        }

        $code = $this->normalisedCountry($request->request->get('country'));

        if ($code === null) {
            $this->addFlash('warning', 'Pick a country first — pulling towns is a paid call, so it is not run across every country at once.');

            return $this->backToScreen();
        }

        $pending = $this->runRepository->countUndeliveredOnQueue(self::SUGGESTION_QUEUE);

        if ($pending > 0) {
            $this->addFlash('warning', \sprintf(
                '%d town batch(es) are still queued. Wait for the current pull to finish — '
                . 'starting another now would pay twice for overlapping answers.',
                $pending,
            ));

            return $this->backToScreen();
        }

        try {
            $country = $this->seedService->resolveCountry($code);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->backToScreen();
        }

        $max = (int) ($request->request->get('max_towns') ?: TownSuggestionService::DEFAULT_MAX);

        try {
            $plan = $this->suggestionService->planRun($country, $max, $this->currentAdmin());
        } catch (\Throwable $e) {
            $this->logger->error('Town suggestion planning failed', ['exception' => $e, 'country' => $code]);
            $this->addFlash('danger', 'Could not queue the town pull: ' . $e->getMessage());

            return $this->backToScreen();
        }

        // planRun() has already flushed, so a consumer that picks one of these up in the same
        // second finds its row committed — the reason the orchestrator's dispatchRun() flushes
        // before dispatching too.
        foreach ($plan['batches'] as $batch) {
            $this->messageBus->dispatch(new SuggestTownsBatchMessage((string) $batch->getId()));
        }

        $batchCount = \count($plan['batches']);

        $this->addFlash('success', \sprintf(
            'Queued %d batch(es) for %s, up to %d town(s). Progress appears in the Pull towns card; '
            . 'batches drain about once a minute.',
            $batchCount,
            $code,
            $max,
        ));

        return $this->backToScreen();
    }

    /**
     * Progress of the newest batched pull for a country, as JSON.
     *
     * Its OWN REAL ROUTE, not the /admin?routeName= entry point — the convention
     * AdminStatsController's docblock sets for admin JSON, and a hard requirement here: the
     * overview() this page is rendered by makes a provider call, so polling it would make a
     * six-batch run cost a hundred extra round trips.
     *
     * KEYED BY COUNTRY, NOT RUN ID, like admin_worldpack_run_status, and for the same reason:
     * the point of queueing is that the operator is free to leave, so the id must not be the
     * only way back to the progress they left behind.
     *
     * GET and read-only — it spends nothing, which is why it is not POST like everything else
     * on this screen.
     */
    #[Route('/admin/locations/suggest-towns/status/{country}', name: 'admin_locations_suggest_towns_status', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function suggestTownsStatus(string $country): Response
    {
        $code = $this->normalisedCountry($country);

        return $this->json([
            'country'    => $code,
            'queueDepth' => $this->runRepository->countUndeliveredOnQueue(self::SUGGESTION_QUEUE),
            'run'        => $code === null ? null : $this->latestSuggestionRunPayload($code),
        ]);
    }

    /**
     * The newest batched pull, shaped for the progress card.
     *
     * FALLS BACK TO ANY COUNTRY when none is given, which is not a nicety — suggestTowns()
     * redirects to this screen with no `?country=`, so without the fallback the card would be
     * blank on the one page load that matters most, immediately after queueing.
     *
     * Returns null when there has never been a pull at all, which the card renders as its empty
     * state rather than as an error.
     *
     * @return array<string, mixed>|null
     */
    private function latestSuggestionRunPayload(?string $country): ?array
    {
        $criteria = ['trigger' => EnrichmentTrigger::ADMIN_TOWN_BATCH];

        if ($country !== null) {
            $criteria['countryCode'] = $country;
        }

        $run = $this->runRepository->findOneBy($criteria, ['startedAt' => 'DESC']);

        if ($run === null) {
            return null;
        }

        $runId   = (string) $run->getId();
        $batches = $this->batchRepository->findForRun($runId);

        return [
            'id'      => $runId,
            'country' => $run->getCountryCode(),
            'status'  => $run->getStatus()->value,
            'model'   => $run->getModel(),
            // Counters on the run are BATCHES for this trigger — see EnrichmentTrigger.
            'totalBatches'     => $run->getQueued(),
            'settledBatches'   => $run->getSucceeded() + $run->getFailed(),
            'failedBatches'    => $run->getFailed(),
            'importedTowns'    => $this->batchRepository->importedForRun($runId),
            'startedAt'        => $run->getStartedAt()->format(\DateTimeInterface::ATOM),
            'finishedAt'       => $run->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            'batches'          => array_map(static fn ($b): array => [
                'index'       => $b->getBatchIndex(),
                'total'       => $b->getTotalBatches(),
                'status'      => $b->getStatus()->value,
                'requested'   => $b->getRequested(),
                'offered'     => $b->getOffered(),
                'imported'    => $b->getImported(),
                'alreadyHeld' => $b->getAlreadyHeld(),
                'attempts'    => $b->getAttempts(),
                'error'       => $b->getErrorMessage(),
            ], $batches),
        ];
    }

    /**
     * Download the enriched geography as JSON.
     *
     * The operator-facing half of "enrich once, import everywhere". Enrichment is a billed API
     * call per town plus a human review pass over every valuation, so this file is the thing
     * that stops dev, prod and every rebuilt database from each paying for it again.
     *
     * GET, and safe to be: it reads and streams, writes nothing, and carries no claims, holds
     * or ledger entries — see LocationImportExportService for exactly what travels.
     */
    #[Route('/admin/locations/export', name: 'admin_locations_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(Request $request, LocationImportExportService $service): StreamedResponse
    {
        $country = $this->normalisedCountry($request->query->get('country'));

        $data = $service->export($country === null ? null : [$country]);
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $filename = sprintf('wunderkind-locations-%s-%s.json', $country ?? 'all', date('Y-m-d'));

        $response = new StreamedResponse(static function () use ($json): void {
            echo $json;
        });

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }

    /**
     * Upload an export and apply it.
     *
     * AN UPSERT, AND THERE IS NO "CLEAR FIRST" OPTION, unlike the world-content importer. A
     * location is referenced by plot_claim with ON DELETE RESTRICT precisely because it can
     * carry real money, so a truncate-then-import would either be refused by the database or
     * mean deleting somebody's purchase first. Rows are matched on (countryCode, slug), updated
     * in place, and nothing is ever deleted.
     *
     * Fields an admin has LOCKED here are skipped and counted — importing geography must not
     * undo the review work the file exists to carry.
     */
    #[Route('/admin/locations/import', name: 'admin_locations_import', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function import(Request $request, LocationImportExportService $service): Response
    {
        if (!$this->isCsrfTokenValid('locations_import', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->backToScreen();
        }

        $file = $request->files->get('locations_file');

        if ($file === null || !$file->isValid()) {
            $this->addFlash('danger', 'No valid file uploaded.');

            return $this->backToScreen();
        }

        $raw = file_get_contents($file->getPathname());

        if ($raw === false) {
            $this->addFlash('danger', 'Could not read the uploaded file.');

            return $this->backToScreen();
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $this->addFlash('danger', 'Invalid JSON — could not parse the uploaded file.');

            return $this->backToScreen();
        }

        $dryRun = $request->request->has('dry_run');
        $result = $service->import($data, $dryRun);

        $summary = sprintf(
            'countries +%d/~%d, locations +%d/~%d, plots +%d, locked fields skipped %d',
            $result['countriesCreated'],
            $result['countriesUpdated'],
            $result['locationsCreated'],
            $result['locationsUpdated'],
            $result['plotsCreated'],
            $result['lockedFieldsSkipped'],
        );

        foreach ($result['errors'] as $error) {
            $this->addFlash('warning', 'Import: ' . $error);
        }

        if ($dryRun) {
            $this->addFlash('info', 'Dry run — nothing was written. Would apply: ' . $summary);

            return $this->backToScreen();
        }

        if (!$result['applied']) {
            $this->addFlash('danger', 'Import did not apply. See the warnings above.');

            return $this->backToScreen();
        }

        $this->addFlash('success', 'Locations imported: ' . $summary);

        if ($result['lockedFieldsSkipped'] > 0) {
            $this->addFlash('info', sprintf(
                '%d locked field group(s) were left alone — a correction made here outranks the file.',
                $result['lockedFieldsSkipped'],
            ));
        }

        // Both are derived from what just changed, and neither is carried in the file.
        $this->addFlash(
            'info',
            'Run app:locations:rivals --stale-only to rebuild rival sets, and '
            . 'app:plots:refresh-estimates to reprice stadiums against the imported land values.',
        );

        return $this->backToScreen();
    }

    /** Country code normalised for the export scope, or null for "everything". */
    private function normalisedCountry(mixed $raw): ?string
    {
        $code = strtoupper(trim((string) $raw));

        return $code === '' ? null : $code;
    }

    /**
     * Back to the enrichment screen.
     *
     * Via generateUrl('admin', ...) and NOT redirectToRoute(), per src/Controller/Admin/CLAUDE.md
     * — EasyAdmin needs the dashboard route or the redirect dies on an "i18n on null" Twig error.
     */
    private function backToScreen(): Response
    {
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));
    }
}
