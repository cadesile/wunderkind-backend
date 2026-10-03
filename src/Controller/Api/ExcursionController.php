<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Excursion;
use App\Enum\TranslatableEntityType;
use App\Repository\ExcursionRepository;
use App\Repository\LanguageRepository;
use App\Service\NarrativeTranslationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Excursion catalogue for the app's excursionStore.
 *
 * The response shape is a contract with src/types/excursion.ts on the client:
 * {excursions: Excursion[], versionHash: string}, camelCase keys throughout.
 * A mismatch fails silently — the client keeps its bundled fallback and the
 * catalogue simply never updates — so keep the two in step.
 *
 * Optional `?lang=` localizes `title`/`body` in place, falling back to English for
 * anything untranslated. An unknown/disabled code silently falls back to the default
 * language (see NarrativeTranslationService) rather than erroring.
 */
#[Route('/api/excursions', name: 'api_excursions', methods: ['GET'])]
class ExcursionController extends AbstractController
{
    public function __construct(
        private readonly ExcursionRepository         $repository,
        private readonly LanguageRepository          $languageRepository,
        private readonly NarrativeTranslationService $translationService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $language = $this->languageRepository->findByCode((string) $request->query->get('lang', ''));
        $map      = $this->translationService->buildLocalizationMap(TranslatableEntityType::EXCURSION, $language);

        $titles = array_filter(array_map(static fn ($field) => $field['title'] ?? null, $map), fn ($v) => $v !== null);
        $bodies = array_filter(array_map(static fn ($field) => $field['body'] ?? null, $map), fn ($v) => $v !== null);
        $result = $this->repository->findActiveWithVersionHash($titles, $bodies);

        $excursions = array_map(
            fn (Excursion $e) => [
                'slug'               => $e->getSlug(),
                'title'              => $this->translationService->localize($e->getSlug(), 'title', $e->getTitle(), $map),
                'body'               => $this->translationService->localize($e->getSlug(), 'body', $e->getBody(), $map),
                // Absolute URL — the app renders this straight into <Image uri>,
                // and a relative path would not resolve on a device.
                'image'              => $this->absoluteImageUrl($request, $e->getImagePath()),
                'costPerPersonPence' => $e->getCostPerPersonPence(),
                'effectValue'        => $e->getEffectValue(),
                'negativeFrequency'  => $e->getNegativeFrequency(),
                'targetAudience'     => $e->getTargetAudience(),
                'postSeasonOnly'     => $e->isPostSeasonOnly(),
                'cooldownWeeks'      => $e->getCooldownWeeks(),
                'active'             => $e->isActive(),
            ],
            $result['excursions'],
        );

        return $this->json([
            'excursions'  => $excursions,
            'versionHash' => $result['versionHash'],
        ]);
    }

    private function absoluteImageUrl(Request $request, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/')
            . '/uploads/excursions/'
            . ltrim($path, '/');
    }
}
