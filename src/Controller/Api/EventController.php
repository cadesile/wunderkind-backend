<?php

namespace App\Controller\Api;

use App\Enum\EventCategory;
use App\Repository\GameEventTemplateRepository;
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
    ) {}

    /**
     * Returns all active event templates for client-side narrative simulation.
     * Cached by the client; no session-specific data. Optional `?category=` narrows to a
     * single EventCategory (e.g. `?category=MATCH_NARRATIVE`) — an unrecognised value is
     * ignored and the full active set is returned, same fail-open behavior as omitting it.
     */
    #[Route('/templates', name: 'api_events_templates', methods: ['GET'])]
    public function templates(Request $request): JsonResponse
    {
        $category = EventCategory::tryFrom((string) $request->query->get('category', ''));
        $items    = $category !== null ? $this->templates->findByCategory($category) : $this->templates->findAllActive();

        $data = array_map(static fn ($t) => [
            'slug'             => $t->getSlug(),
            'category'         => $t->getCategory()->value,
            'weight'           => $t->getWeight(),
            'title'            => $t->getTitle(),
            'bodyTemplate'     => $t->getBodyTemplate(),
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
