<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\Tier;
use App\Repository\ClubRepository;
use App\Repository\PlayerRepository;
use App\Service\ClubInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/squad')]
#[IsGranted('ROLE_CLUB')]
class SquadController extends AbstractController
{
    public function __construct(
        private readonly PlayerRepository $playerRepository,
        private readonly ClubRepository $clubRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'api_squad_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['error' => 'No club found.'], Response::HTTP_NOT_FOUND);
        }

        $tierParam    = $request->query->get('tier');
        $tier         = $tierParam !== null ? Tier::tryFrom($tierParam) : null;
        $countryParam = $request->query->get('country');
        $nationality  = $countryParam !== null ? ClubInitializationService::countryToNationality($countryParam) : null;

        $activePlayers = $this->playerRepository->findActiveByClub($club);

        if ($tier !== null) {
            [$min, $max] = $tier->scoreRange();
            $activePlayers = array_filter(
                $activePlayers,
                fn($p) => $p->getCurrentAbility() >= $min && $p->getCurrentAbility() <= $max
            );
        }

        if ($nationality !== null) {
            $activePlayers = array_filter(
                $activePlayers,
                fn($p) => $p->getNationality() === $nationality
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
                    'determination'   => $p->getDetermination(),
                    'professionalism' => $p->getProfessionalism(),
                    'ambition'        => $p->getAmbition(),
                    'loyalty'         => $p->getLoyalty(),
                    'adaptability'    => $p->getAdaptability(),
                    'pressure'        => $p->getPressure(),
                    'temperament'     => $p->getTemperament(),
                    'consistency'     => $p->getConsistency(),
                ],
                'agentName'     => $player->getAgent()?->getName(),
            ];
        }, $activePlayers);

        return $this->json(['players' => array_values($players)]);
    }

    #[Route('/release/{id}', name: 'api_squad_release', methods: ['POST'])]
    public function release(string $id): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['error' => 'No club found.'], Response::HTTP_NOT_FOUND);
        }

        $player = $this->playerRepository->find($id);

        if ($player === null) {
            return $this->json(['error' => 'Player not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($player->getClub()?->getId() !== $club->getId()) {
            return $this->json(['error' => 'Player does not belong to your club.'], Response::HTTP_FORBIDDEN);
        }

        $playerName = $player->getFirstName() . ' ' . $player->getLastName();

        $player->setClub(null);
        $this->em->flush();

        return $this->json([
            'success'    => true,
            'playerId'   => $id,
            'playerName' => $playerName,
            'message'    => "{$playerName} has been released.",
        ]);
    }
}
