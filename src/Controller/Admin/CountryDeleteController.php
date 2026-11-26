<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Location\CountryDeletionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The guarded country delete: a confirm page listing what the country owns, then a POST that
 * needs the typed country code. Replaces EasyAdmin's stock delete on the Countries CRUD, which
 * crashed on location's ON DELETE RESTRICT and, had it not, would have orphaned every table
 * that stores the code as a bare string. The rules live in CountryDeletionService.
 *
 * Both routes are reached via /admin?routeName=... (see src/Controller/Admin/CLAUDE.md), so
 * `code` arrives as a forwarded request attribute from routeParams, with the query string and
 * POST body as fallbacks.
 */
#[IsGranted('ROLE_ADMIN')]
class CountryDeleteController extends AbstractController
{
    public function __construct(
        private readonly CountryDeletionService $deletion,
    ) {}

    #[Route('/admin/countries/delete', name: 'admin_country_delete_confirm', methods: ['GET'])]
    public function confirm(Request $request, ?string $code = null): Response
    {
        $code = $this->resolveCode($request, $code);

        if ($code === null) {
            return $this->backToIndex();
        }

        $inventory = $this->deletion->inventory($code);

        if (!$inventory['exists']) {
            $this->addFlash('warning', "Country {$code} does not exist.");

            return $this->backToIndex();
        }

        return $this->render('admin/confirm_delete_country.html.twig', [
            'code'      => $code,
            'inventory' => $inventory,
        ]);
    }

    #[Route('/admin/countries/delete/run', name: 'admin_country_delete', methods: ['POST'])]
    public function delete(Request $request, ?string $code = null): Response
    {
        $code = $this->resolveCode($request, $code);

        if ($code === null) {
            return $this->backToIndex();
        }

        if (!$this->isCsrfTokenValid('delete_country_' . $code, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token. Nothing was deleted.');

            return $this->backToConfirm($code);
        }

        $typed = strtoupper(trim((string) $request->request->get('confirmCode', '')));
        if ($typed !== $code) {
            $this->addFlash('danger', "Type {$code} to confirm. Nothing was deleted.");

            return $this->backToConfirm($code);
        }

        $result = $this->deletion->delete($code);

        if (!$result['deleted']) {
            $this->addFlash('danger', "{$code} was not deleted: " . implode('; ', $result['reasons']) . '.');

            return $this->backToConfirm($code);
        }

        $parts = array_map(static fn (array $r): string => "{$r['count']} {$r['label']}", $result['removed']);
        $this->addFlash('success', "Country {$code} deleted" . ($parts === [] ? '.' : ', with ' . implode(', ', $parts) . '.'));

        return $this->backToIndex();
    }

    private function resolveCode(Request $request, ?string $code): ?string
    {
        $nested = $request->query->all('routeParams');
        $code ??= $request->request->get('code') ?? $request->query->get('code') ?? ($nested['code'] ?? null);

        if (!\is_string($code)) {
            return null;
        }

        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
    }

    private function backToConfirm(string $code): Response
    {
        return $this->redirect($this->generateUrl('admin', [
            'routeName'   => 'admin_country_delete_confirm',
            'routeParams' => ['code' => $code],
        ]));
    }

    private function backToIndex(): Response
    {
        // EasyAdmin's pretty-URL route for the Countries list.
        return $this->redirect($this->generateUrl('admin_country_profile_index'));
    }
}
