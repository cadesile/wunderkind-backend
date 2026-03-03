<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Player;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/squad')]
#[IsGranted('ROLE_ACADEMY')]
class SquadController extends AbstractController
{
    #[Route('', name: 'api_squad_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $user->getAcademy();

        if ($academy === null) {
            return $this->json(['error' => 'No academy found.'], Response::HTTP_NOT_FOUND);
        }

        $players = $academy->getPlayers()->map(function (Player $player): array {
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
        })->toArray();

        return $this->json(['players' => array_values($players)]);
    }
}
