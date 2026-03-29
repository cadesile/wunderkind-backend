<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\Tier;
use App\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/squad')]
#[IsGranted('ROLE_ACADEMY')]
class SquadController extends AbstractController
{
    public function __construct(private readonly PlayerRepository $playerRepository) {}

    #[Route('', name: 'api_squad_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $user->getAcademy();

        if ($academy === null) {
            return $this->json(['error' => 'No academy found.'], Response::HTTP_NOT_FOUND);
        }

        $tierParam = $request->query->get('tier');
        $tier      = $tierParam !== null ? Tier::tryFrom($tierParam) : null;

        $activePlayers = $this->playerRepository->findActiveByAcademy($academy);

        if ($tier !== null) {
            [$min, $max] = $tier->scoreRange();
            $activePlayers = array_filter(
                $activePlayers,
                fn($p) => $p->getCurrentAbility() >= $min && $p->getCurrentAbility() <= $max
            );
        }

        $players = array_map(function ($player): array {
            $p = $player->getPersonality();

            return [
                'id'            => $player->getId()->toRfc4122(),
                'firstName'     => $player->getFirstName(),
                'lastName'      => $player->getLastName(),
                'dateOfBirth'   => $player->getDateOfBirth()->format('Y-m-d'),
                'nationality'   => $player->getNationality(),
                'position'      => $player->getPositionValue(),
                'status'        => $player->getStatusValue(),
                'morale'        => $player->getMorale(),
                'contractValue' => $player->getContractValue(),
                'attributes'    => [
                    'pace'      => $player->getPace(),
                    'technical' => $player->getTechnical(),
                    'vision'    => $player->getVision(),
                    'power'     => $player->getPower(),
                    'stamina'   => $player->getStamina(),
                    'heart'     => $player->getHeart(),
                    'overall'   => $player->getOverall(),
                ],
                'physical'      => [
                    'height' => $player->getHeight(),
                    'weight' => $player->getWeight(),
                ],
                'potential'     => $player->getPotential(),
                'personality'   => [
                    'confidence' => $p->getConfidence(),
                    'maturity'   => $p->getMaturity(),
                    'teamwork'   => $p->getTeamwork(),
                    'leadership' => $p->getLeadership(),
                    'ego'        => $p->getEgo(),
                    'bravery'    => $p->getBravery(),
                    'greed'      => $p->getGreed(),
                    'loyalty'    => $p->getLoyalty(),
                ],
                'agentName'     => $player->getAgent()?->getName(),
            ];
        }, $activePlayers);

        return $this->json(['players' => array_values($players)]);
    }
}
