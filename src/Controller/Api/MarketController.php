<?php

namespace App\Controller\Api;

use App\Dto\MarketAssignRequest;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Sponsor;
use App\Entity\Staff;
use App\Entity\User;
use App\Enum\MarketEntityType;
use App\Repository\AcademyRepository;
use App\Repository\AgentRepository;
use App\Repository\InvestorRepository;
use App\Repository\PlayerRepository;
use App\Repository\ScoutRepository;
use App\Repository\SponsorRepository;
use App\Repository\StaffRepository;
use App\Service\MarketDataService;
use App\Service\MarketPoolService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/market')]
class MarketController extends AbstractController
{
    // -------------------------------------------------------------------------
    // New endpoints
    // -------------------------------------------------------------------------

    /** Maps the 2-letter academy country code to its player nationality string. */
    private const COUNTRY_TO_NATIONALITY = [
        'EN' => 'English',
        'IT' => 'Italian',
        'DE' => 'German',
        'ES' => 'Spanish',
        'BR' => 'Brazilian',
        'AR' => 'Argentine',
        'NL' => 'Dutch',
    ];

    #[Route('/data', name: 'api_market_pool_data', methods: ['GET'])]
    #[IsGranted('ROLE_ACADEMY')]
    public function data(Request $request, MarketDataService $service): JsonResponse
    {
        $countryCode = $request->query->get('country');
        $nationality = $countryCode !== null
            ? (self::COUNTRY_TO_NATIONALITY[$countryCode] ?? null)
            : null;

        $response = $this->json($service->getMarketSnapshot($nationality));
        $response->setMaxAge(300); // 5-minute cache hint for client
        return $response;
    }

    #[Route('/prospects', name: 'api_market_prospects', methods: ['GET'])]
    #[IsGranted('ROLE_ACADEMY')]
    public function prospects(MarketDataService $service): JsonResponse
    {
        $players  = $service->getProspectSnapshot();
        $response = $this->json(['players' => $players]);
        $response->setMaxAge(3600); // 1-hour cache hint — prospects refresh slowly
        return $response;
    }

    #[Route('/assign', name: 'api_market_assign', methods: ['POST'])]
    #[IsGranted('ROLE_ACADEMY')]
    public function assign(
        #[MapRequestPayload] MarketAssignRequest $dto,
        MarketPoolService  $pool,
        AcademyRepository  $academyRepo,
        PlayerRepository   $playerRepo,
        StaffRepository    $staffRepo,
        ScoutRepository    $scoutRepo,
        InvestorRepository $investorRepo,
        SponsorRepository  $sponsorRepo,
    ): JsonResponse {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $academyRepo->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'No academy found for this user.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $uuid   = Uuid::fromString($dto->entityId);
            $entity = match ($dto->entityType) {
                MarketEntityType::PLAYER   => $playerRepo->find($uuid),
                MarketEntityType::COACH    => $staffRepo->find($uuid),
                MarketEntityType::SCOUT    => $scoutRepo->find($uuid),
                MarketEntityType::SPONSOR  => $sponsorRepo->find($uuid),
                MarketEntityType::INVESTOR => $investorRepo->find($uuid),
            };
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid entity ID.'], Response::HTTP_BAD_REQUEST);
        }

        if ($entity === null) {
            return $this->json(['error' => 'Entity not found.'], Response::HTTP_NOT_FOUND);
        }

        // Verify entity is available in the pool (not already assigned)
        $inPool = match (true) {
            $entity instanceof Player   => $entity->isInMarketPool(),
            $entity instanceof Staff    => $entity->isInMarketPool(),
            $entity instanceof Sponsor  => $entity->isInMarketPool(),
            $entity instanceof Investor => $entity->isInMarketPool(),
            $entity instanceof Scout    => true, // Scouts are always available
            default                     => false,
        };

        if (!$inPool) {
            return $this->json(['error' => 'Entity is not available in the market pool.'], Response::HTTP_CONFLICT);
        }

        try {
            $pool->assignToAcademy($entity, $academy);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['success' => true, 'entityId' => $dto->entityId]);
    }

    // -------------------------------------------------------------------------
    // Legacy endpoint (backward compat)
    // -------------------------------------------------------------------------

    #[Route('/legacy', name: 'api_market_data_legacy', methods: ['GET'])]
    public function legacyData(
        AgentRepository    $agents,
        ScoutRepository    $scouts,
        InvestorRepository $investors,
        SponsorRepository  $sponsors,
    ): JsonResponse {
        return $this->json([
            'agents'    => array_map(fn($a) => [
                'id'             => $a->getId()->toRfc4122(),
                'name'           => $a->getName(),
                'nationality'    => $a->getNationality(),
                'experience'     => $a->getExperience(),
                'rating'         => $a->getRating(),
                'commissionRate' => $a->getCommissionRate(),
            ], $agents->findAll()),
            'scouts'    => array_map(fn($s) => [
                'id'          => $s->getId()->toRfc4122(),
                'name'        => $s->getName(),
                'nationality' => $s->getNationality(),
                'experience'  => $s->getExperience(),
            ], $scouts->findAll()),
            'investors' => array_map(fn($i) => [
                'id'                       => $i->getId()->toRfc4122(),
                'company'                  => $i->getCompany(),
                'nationality'              => $i->getNationality(),
                'size'                     => $i->getSize()->value,
                'expectedReturnPercentage' => $i->getExpectedReturnPercentage(),
            ], $investors->findAllActive()),
            'sponsors'  => array_map(fn($s) => [
                'id'                       => $s->getId()->toRfc4122(),
                'company'                  => $s->getCompany(),
                'nationality'              => $s->getNationality(),
                'size'                     => $s->getSize()->value,
                'expectedReturnPercentage' => $s->getExpectedReturnPercentage(),
            ], $sponsors->findAllActive()),
        ]);
    }
}
