<?php

namespace App\Controller\Api;

use App\Dto\MarketDataResponse;
use App\Repository\AgentRepository;
use App\Repository\InvestorRepository;
use App\Repository\ScoutRepository;
use App\Repository\SponsorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class MarketController extends AbstractController
{
    public function __construct(
        private AgentRepository    $agents,
        private ScoutRepository    $scouts,
        private InvestorRepository $investors,
        private SponsorRepository  $sponsors,
    ) {}

    #[Route('/api/market-data', name: 'api_market_data', methods: ['GET'])]
    public function marketData(): JsonResponse
    {
        $agentData = array_map(fn($a) => [
            'id'             => $a->getId()->toRfc4122(),
            'name'           => $a->getName(),
            'nationality'    => $a->getNationality(),
            'experience'     => $a->getExperience(),
            'rating'         => $a->getRating(),
            'commissionRate' => $a->getCommissionRate(),
            'isUniversal'    => $a->isUniversal(),
        ], $this->agents->findAll());

        $scoutData = array_map(fn($s) => [
            'id'          => $s->getId()->toRfc4122(),
            'name'        => $s->getName(),
            'nationality' => $s->getNationality(),
            'experience'  => $s->getExperience(),
        ], $this->scouts->findAll());

        $investorData = array_map(fn($i) => [
            'id'                       => $i->getId()->toRfc4122(),
            'company'                  => $i->getCompany(),
            'nationality'              => $i->getNationality(),
            'size'                     => $i->getSize()->value,
            'expectedReturnPercentage' => $i->getExpectedReturnPercentage(),
        ], $this->investors->findAllActive());

        $sponsorData = array_map(fn($s) => [
            'id'                       => $s->getId()->toRfc4122(),
            'company'                  => $s->getCompany(),
            'nationality'              => $s->getNationality(),
            'size'                     => $s->getSize()->value,
            'expectedReturnPercentage' => $s->getExpectedReturnPercentage(),
        ], $this->sponsors->findAllActive());

        return $this->json(new MarketDataResponse($agentData, $scoutData, $investorData, $sponsorData));
    }
}
