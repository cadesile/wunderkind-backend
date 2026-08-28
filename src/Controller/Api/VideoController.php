<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\GameConfigRepository;
use App\Service\YouTubeFeedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public endpoint — no JWT required.
 *
 * Recent uploads from the configured YouTube channel. Returns an empty list
 * rather than an error when the channel is unset or the feed is unreachable;
 * callers hide their video section on an empty list.
 */
#[Route('/api')]
class VideoController extends AbstractController
{
    public function __construct(
        private readonly YouTubeFeedService $youTubeFeedService,
        private readonly GameConfigRepository $gameConfigRepository,
    ) {}

    #[Route('/videos/latest', name: 'api_videos_latest', methods: ['GET'])]
    public function latest(): JsonResponse
    {
        $channelId = $this->gameConfigRepository->getConfig()->getYoutubeChannelId();

        return $this->json([
            'channelId' => $channelId,
            'videos'    => $channelId !== null ? $this->youTubeFeedService->getVideos($channelId) : [],
        ]);
    }
}
