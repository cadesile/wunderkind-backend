<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\LanguageRepository;
use App\Service\TranslationCatalogueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The generic UI-copy translation catalogue — public, unauthenticated, needed before login.
 * Narrative content (events/facilities/excursions) is NOT served here; it's localized in
 * place on its own endpoints via ?lang= instead. See NarrativeTranslationService.
 *
 * Unlike the localize-in-place endpoints, an unknown/disabled code is a hard 404 here — this
 * endpoint's entire contract is "give me exactly this language's file."
 */
#[Route('/api/translations')]
class TranslationController extends AbstractController
{
    public function __construct(
        private readonly LanguageRepository         $languageRepository,
        private readonly TranslationCatalogueService $catalogueService,
    ) {}

    #[Route('/{code}', name: 'api_translations_file', methods: ['GET'])]
    public function catalogue(string $code): JsonResponse
    {
        [$requested, $default, $error] = $this->resolve($code);
        if ($error !== null) {
            return $error;
        }

        $response = new JsonResponse($this->catalogueService->buildFullFile($requested, $default));
        $response->setMaxAge(3600);
        $response->setPublic();

        return $response;
    }

    #[Route('/{code}/version', name: 'api_translations_version', methods: ['GET'])]
    public function version(string $code): JsonResponse
    {
        [$requested, $default, $error] = $this->resolve($code);
        if ($error !== null) {
            return $error;
        }

        $response = new JsonResponse([
            'code'        => $requested->getCode(),
            'versionHash' => $this->catalogueService->buildVersionHash($requested, $default),
        ]);
        $response->setMaxAge(300);
        $response->setPublic();

        return $response;
    }

    /** @return array{0: ?\App\Entity\Language, 1: ?\App\Entity\Language, 2: ?JsonResponse} */
    private function resolve(string $code): array
    {
        $default = $this->languageRepository->findDefault();
        $requested = $this->languageRepository->findByCode($code);

        if ($requested === null || !$requested->isEnabled() || $default === null) {
            return [null, null, new JsonResponse(['error' => "No enabled language with code '{$code}'."], 404)];
        }

        return [$requested, $default, null];
    }
}
