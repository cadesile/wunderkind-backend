<?php

namespace App\Controller\Api;

use App\Enum\EventCategory;
use App\Enum\TranslatableEntityType;
use App\Repository\GameEventTemplateRepository;
use App\Repository\LanguageRepository;
use App\Service\NarrativeTranslationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/events')]
#[IsGranted('ROLE_CLUB')]
class EventController extends AbstractController
{
    public function __construct(
        private readonly GameEventTemplateRepository $templates,
        private readonly LanguageRepository          $languageRepository,
        private readonly NarrativeTranslationService $translationService,
    ) {}

    /**
     * Returns all active event templates for client-side narrative simulation.
     * Cached by the client; no session-specific data. Optional `?category=` narrows to a
     * single EventCategory (e.g. `?category=MATCH_NARRATIVE`) — an unrecognised value is
     * ignored and the full active set is returned, same fail-open behavior as omitting it.
     *
     * Optional `?lang=` localizes `title`/`bodyTemplate` in place, falling back to the
     * canonical English text for anything untranslated. An unknown/disabled code silently
     * falls back to the default language — this is core gameplay data, so a bad code must
     * never break it (contrast the strict 404 on GET /api/translations/{code}).
     */
    #[Route('/templates', name: 'api_events_templates', methods: ['GET'])]
    public function templates(Request $request): JsonResponse
    {
        $category = EventCategory::tryFrom((string) $request->query->get('category', ''));
        $items    = $category !== null ? $this->templates->findByCategory($category) : $this->templates->findAllActive();

        $language = $this->languageRepository->findByCode((string) $request->query->get('lang', ''));
        $map      = $this->translationService->buildLocalizationMap(TranslatableEntityType::GAME_EVENT_TEMPLATE, $language);

        $data = array_map(fn ($t) => [
            'slug'             => $t->getSlug(),
            'category'         => $t->getCategory()->value,
            'weight'           => $t->getWeight(),
            'title'            => $this->translationService->localize($t->getSlug(), 'title', $t->getTitle(), $map),
            'bodyTemplate'     => $this->translationService->localize($t->getSlug(), 'bodyTemplate', $t->getBodyTemplate(), $map),
            'impacts'          => $t->getImpacts(),
            'firingConditions' => $t->getFiringConditions(),
            'severity'         => $t->getSeverity(),
            'chainedEvents'    => $t->getChainedEventsWithoutNotes(),
            'noInteract'       => $t->isNoInteract(),
        ], $items);

        $response = new JsonResponse(['templates' => $data]);
        $response->setMaxAge(3600);
        $response->setPublic();

        return $response;
    }
}
