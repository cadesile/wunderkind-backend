<?php

namespace App\Controller\Api;

use App\Repository\GameConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class AppLinksController extends AbstractController
{
    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
    ) {}

    #[Route('/app-links', name: 'api_app_links', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $config = $this->gameConfigRepository->getConfig();

        return $this->json([
            'android'          => $config->getAndroidDownloadUrl(),
            'ios'              => $config->getIosDownloadUrl(),
            'recaptchaSiteKey' => $config->getRecaptchaSiteKey(),
            'facebook'         => $config->getFacebookPageUrl(),
            'x'                => $config->getXProfileUrl(),
        ]);
    }
}
