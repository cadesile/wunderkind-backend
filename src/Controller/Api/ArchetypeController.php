<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\PlayerArchetypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/archetypes', name: 'api_archetypes', methods: ['GET'])]
#[IsGranted('ROLE_ACADEMY')]
class ArchetypeController extends AbstractController
{
    public function __construct(
        private readonly PlayerArchetypeRepository $repository,
    ) {}

    public function __invoke(): JsonResponse
    {
        $result = $this->repository->findAllWithVersionHash();

        $archetypes = array_map(
            fn ($a) => [
                'id'           => $a->getId(),
                'name'         => $a->getName(),
                'description'  => $a->getDescription(),
                'traitMapping' => $a->getTraitMapping(),
            ],
            $result['archetypes'],
        );

        return $this->json([
            'archetypes'  => $archetypes,
            'versionHash' => $result['versionHash'],
        ]);
    }
}
