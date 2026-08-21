<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\PlayerArchetype;
use App\Repository\PlayerArchetypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the curated archetype catalogue — 10 positive, 10 negative.
 *
 * The response shape is a contract with the client's TS types: camelCase throughout, matching
 * the {items, versionHash} envelope used by ExcursionController. A mismatch fails silently on
 * the client, so do not rename keys without updating the frontend.
 *
 * Public by design (security.yaml grants PUBLIC_ACCESS) — the catalogue is static reference
 * data and the client needs it before a JWT exists.
 */
#[Route('/api/archetypes', name: 'api_archetypes', methods: ['GET'])]
class ArchetypeController extends AbstractController
{
    public function __construct(
        private readonly PlayerArchetypeRepository $repository,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->repository->findAllWithVersionHash();

        $archetypes = array_map(
            fn (PlayerArchetype $a) => [
                'id'           => $a->getId(),
                'slug'         => $a->getSlug(),
                'name'         => $a->getName(),
                'description'  => $a->getDescription(),
                'polarity'     => $a->getPolarity()->value,
                'traitWeights' => $a->getTraitWeights(),
            ],
            $result['archetypes'],
        );

        $response = $this->json([
            'archetypes'  => $archetypes,
            'versionHash' => $result['versionHash'],
        ]);

        // The version hash is already a content digest, so it doubles as the ETag and lets a
        // client cache-check cost a 304 instead of the full catalogue.
        $response->setEtag($result['versionHash']);
        $response->setPublic();
        $response->isNotModified($request);

        return $response;
    }
}
