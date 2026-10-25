<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Country;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use App\Service\StarterPackDiagnosticService;
use App\Service\StarterPackService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Can a new club in this country actually get a starter pack?" — the browser twin of
 * `app:starter-pack:check`, for answering the question on a deployed box without SSH.
 *
 * Read-only: it runs count queries only and consumes no pool entity, so it is safe to
 * press against production.
 *
 * GET-only, with the country in the query string rather than a POST body, so a result is
 * a linkable URL that can be pasted into a bug report.
 */
class StarterPackCheckController extends AbstractController
{
    /** See warm()'s docblock: a cold warm measured ~20s, against a 30s PHP default. */
    private const WARM_TIME_LIMIT_SECONDS = 600;

    public function __construct(
        private readonly StarterPackDiagnosticService $diagnostics,
    ) {}

    #[Route('/admin/starter-pack-check', name: 'admin_starter_pack_check')]
    #[IsGranted('ROLE_ADMIN')]
    public function check(Request $request): Response
    {
        $country = strtoupper(trim((string) $request->query->get('country', '')));
        $tier    = (int) $request->query->get('tier', (string) StarterPackService::DEFAULT_STARTER_TIER);
        $tier    = max(1, min(8, $tier));

        return $this->render('admin/starter_pack_check.html.twig', [
            'countries'   => Country::generationCapableLabels(),
            'country'     => $country,
            'tier'        => $tier,
            'defaultTier' => StarterPackService::DEFAULT_STARTER_TIER,
            'result'      => $country !== '' ? $this->diagnostics->diagnose($country, $tier) : null,
        ]);
    }

    /**
     * Tops the pool up for one country (app:pool:warm), so a country whose check came back
     * short can be made servable without SSH — then redirects straight back into the check so
     * the effect is visible rather than claimed.
     *
     * POST, CSRF-guarded, and routed through EasyAdmin's entry point: unlike check() above
     * this one creates data, and the check screen extends an @EasyAdmin template, whose `ea`
     * context only exists for routes EasyAdmin created (see src/Controller/Admin/CLAUDE.md).
     *
     * The country comes from the server's own last-checked value, not from the form's select.
     * Warming is only meaningful for a country you have just looked at, and binding it to the
     * rendered result means it cannot act on a dropdown the admin changed but never submitted.
     *
     * **set_time_limit() is deliberate.** A cold warm generates 500 players, 150 staff and 50
     * scouts for the nationality — measured at ~20s locally, which sits close enough to PHP's
     * 30s default max_execution_time that a slower box would 504 halfway through, leaving a
     * half-warmed pool and no output. Warming is a top-up to minimums, so a repeat press is
     * safe either way, but timing out mid-run tells the admin nothing about what happened.
     */
    #[Route('/admin/starter-pack-check/warm', name: 'admin_starter_pack_warm', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function warm(Request $request, KernelInterface $kernel): Response
    {
        $country = strtoupper(trim((string) $request->request->get('country', '')));
        $tier    = max(1, min(8, (int) $request->request->get('tier', (string) StarterPackService::DEFAULT_STARTER_TIER)));

        $back = $this->generateUrl('admin', [
            'routeName'   => 'admin_starter_pack_check',
            'routeParams' => ['country' => $country, 'tier' => $tier],
        ]);

        if (!$this->isCsrfTokenValid('starter_pack_warm', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($back);
        }

        if (Country::tryFrom($country) === null) {
            $this->addFlash('danger', 'Pick a country and run the check before warming its pool.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_starter_pack_check']));
        }

        set_time_limit(self::WARM_TIME_LIMIT_SECONDS);

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput(['command' => 'app:pool:warm', 'country' => $country]), $output);

        if ($status !== 0) {
            $this->addFlash('danger', sprintf('Pool warm for %s exited with status %d.', $country, $status));
        } else {
            $this->addFlash('success', sprintf('Pool warmed for %s — the check below is re-run against the new counts.', $country));
        }

        $this->addFlash('info', nl2br(htmlspecialchars(trim($output->fetch()))));

        return $this->redirect($back);
    }
}
