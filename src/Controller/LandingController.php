<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ExcursionRepository;
use App\Repository\GameConfigRepository;
use App\Service\ArchetypeShowcaseService;
use App\Service\WorldOverviewService;
use App\Service\YouTubeFeedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public marketing site.
 *
 * Replaces the former HomeController, which streamed a 3,229-line static
 * public/index.html straight off disk. The page is now Twig partials so it can
 * carry more than one route and so its figures can come from the database
 * instead of being retyped into the copy.
 *
 * World figures are read from WorldOverviewService directly rather than fetched
 * from /api/world/overview: the page is same-origin with the API, so a round
 * trip would only buy a flash of empty pyramid and a layout shift. The endpoint
 * still exists for off-origin consumers.
 */
class LandingController extends AbstractController
{
    public function __construct(
        private readonly WorldOverviewService $worldOverviewService,
        private readonly YouTubeFeedService $youTubeFeedService,
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly ExcursionRepository $excursionRepository,
        private readonly ArchetypeShowcaseService $archetypeShowcase,
    ) {}

    #[Route('/', name: 'landing_home', methods: ['GET'])]
    public function index(): Response
    {
        $config = $this->gameConfigRepository->getConfig();

        $videos = [];
        if ($config->getYoutubeChannelId() !== null) {
            $videos = $this->youTubeFeedService->getVideos($config->getYoutubeChannelId());
        }

        return $this->render('landing/index.html.twig', [
            'world'      => $this->worldOverviewService->getOverview(),
            'videos'     => $videos,
            'excursions' => $this->excursionRepository->findBy(['active' => true], ['title' => 'ASC'], 6),
            // Re-sampled every request, so the shop window rotates between visits.
            'archetypes' => $this->archetypeShowcase->sample(),
        ]);
    }

    /**
     * Standalone page, deliberately not a modal on the home page: Google Play
     * requires a deletion URL that can be pasted into the Play Console and that
     * works for someone who has never installed the app.
     */
    #[Route('/delete-account', name: 'landing_delete_account', methods: ['GET'])]
    public function deleteAccount(): Response
    {
        return $this->render('landing/delete_account.html.twig');
    }
}
