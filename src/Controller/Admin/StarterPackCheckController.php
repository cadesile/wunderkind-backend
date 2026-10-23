<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Country;
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
}
