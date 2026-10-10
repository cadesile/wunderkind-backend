<?php

namespace App\Controller\Api;

use App\Dto\ClubKitIdentityRequest;
use App\Entity\Club;
use App\Entity\User;
use App\Service\ClubResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reads/sets the authenticated club's own kit+badge identity — the on-device
 * "owner" customizes these, they're synced up here, and served back out on
 * the public leaderboard (LeaderboardItemDto) and the landing page's live
 * telemetry feed (LiveTelemetryService) so a real club's branding shows up
 * wherever the club is displayed publicly. Same partial-update convention as
 * OwnerAvatarController — mirror that pattern if this one ever needs to
 * change, they should stay structurally identical.
 *
 * Full contract for the frontend: docs/api/club-kit-identity.md.
 */
#[Route('/api/club/kit-identity')]
class ClubKitIdentityController extends AbstractController
{
    public function __construct(private readonly ClubResolver $clubResolver) {}

    #[Route('', name: 'api_club_kit_identity_get', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function get(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'No club found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serialize($club));
    }

    #[Route('', name: 'api_club_kit_identity_set', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function set(
        Request $request,
        #[MapRequestPayload] ClubKitIdentityRequest $dto,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'No club found.'], Response::HTTP_NOT_FOUND);
        }

        // Partial update: only keys actually present in the JSON body are applied —
        // the validated $dto alone can't distinguish "omitted" from "explicitly
        // null", since every property defaults to null. Same pattern as
        // OwnerAvatarController::set().
        $submitted = (array) $request->toArray();

        if (array_key_exists('homeKitConfig', $submitted)) {
            $club->setHomeKitConfig($dto->homeKitConfig);
        }
        if (array_key_exists('awayKitConfig', $submitted)) {
            $club->setAwayKitConfig($dto->awayKitConfig);
        }
        if (array_key_exists('badgeConfig', $submitted)) {
            $club->setBadgeConfig($dto->badgeConfig);
        }

        $em->flush();

        return $this->json($this->serialize($club));
    }

    /** @return array{homeKitConfig: ?array, awayKitConfig: ?array, badgeConfig: ?array} */
    private function serialize(Club $club): array
    {
        return [
            'homeKitConfig' => $club->getHomeKitConfig(),
            'awayKitConfig' => $club->getAwayKitConfig(),
            'badgeConfig'   => $club->getBadgeConfig(),
        ];
    }
}
