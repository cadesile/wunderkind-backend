<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Language;
use App\Repository\LanguageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated — needed for locale selection before a client has logged in.
 */
#[Route('/api/languages', name: 'api_languages', methods: ['GET'])]
class LanguageController extends AbstractController
{
    public function __construct(
        private readonly LanguageRepository $languageRepository,
    ) {}

    public function __invoke(): JsonResponse
    {
        $languages = array_map(static fn (Language $l) => [
            'code'      => $l->getCode(),
            'name'      => $l->getName(),
            'isDefault' => $l->isDefault(),
        ], $this->languageRepository->findEnabled());

        $response = new JsonResponse(['languages' => $languages]);
        $response->setMaxAge(3600);
        $response->setPublic();

        return $response;
    }
}
