<?php

namespace App\Controller\Api;

use App\Repository\GameEventTemplateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/events')]
#[IsGranted('ROLE_ACADEMY')]
class EventController extends AbstractController
{
    public function __construct(
        private readonly GameEventTemplateRepository $templates,
    ) {}

    /**
     * Returns all active event templates for client-side narrative simulation.
     * Cached by the client; no session-specific data.
     */
    #[Route('/templates', name: 'api_events_templates', methods: ['GET'])]
    public function templates(): JsonResponse
    {
        $items = $this->templates->findAllActive();

        $data = array_map(static fn ($t) => [
            'slug'         => $t->getSlug(),
            'category'     => $t->getCategory()->value,
            'weight'       => $t->getWeight(),
            'title'        => $t->getTitle(),
            'bodyTemplate' => $t->getBodyTemplate(),
            'impacts'      => $t->getImpacts(),
        ], $items);

        $response = new JsonResponse(['templates' => $data]);
        $response->setMaxAge(3600);
        $response->setPublic();

        return $response;
    }
}
