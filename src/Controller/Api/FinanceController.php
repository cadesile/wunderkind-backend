<?php

namespace App\Controller\Api;

use App\Entity\Investor;
use App\Entity\Sponsor;
use App\Entity\User;
use App\Enum\SponsorStatus;
use App\Repository\AcademyRepository;
use App\Repository\InvestorRepository;
use App\Repository\SponsorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/finance')]
#[IsGranted('ROLE_ACADEMY')]
class FinanceController extends AbstractController
{
    public function __construct(
        private readonly AcademyRepository  $academyRepository,
        private readonly InvestorRepository $investorRepository,
        private readonly SponsorRepository  $sponsorRepository,
    ) {}

    #[Route('/overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found'], Response::HTTP_NOT_FOUND);
        }

        $investors     = $academy->getInvestors()->toArray();
        $activeSponsors = $academy->getActiveSponsors()->toArray();

        $totalOwnership = array_sum(array_map(fn (Investor $i) => $i->getPercentageOwned(), $investors));

        return $this->json([
            'monthlyRevenue'     => $academy->getMonthlyRevenue(),
            'activeSponsors'     => count($activeSponsors),
            'totalOwnershipGiven' => round($totalOwnership, 2),
            'investors'          => array_map($this->serializeInvestor(...), $investors),
        ]);
    }

    #[Route('/investors', methods: ['GET'])]
    public function investors(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'investors' => array_map(
                $this->serializeInvestor(...),
                $academy->getInvestors()->toArray()
            ),
        ]);
    }

    #[Route('/sponsors', methods: ['GET'])]
    public function sponsors(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'sponsors' => array_map(
                $this->serializeSponsor(...),
                $academy->getSponsors()->toArray()
            ),
        ]);
    }

    #[Route('/sponsors/{id}/terminate', methods: ['POST'])]
    public function terminateSponsor(string $id): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found'], Response::HTTP_NOT_FOUND);
        }

        $sponsor = $this->sponsorRepository->find($id);

        if ($sponsor === null || $sponsor->getAcademy()?->getId() !== $academy->getId()) {
            return $this->json(['error' => 'Sponsor not found'], Response::HTTP_NOT_FOUND);
        }

        if ($sponsor->getStatus() !== SponsorStatus::ACTIVE) {
            return $this->json(['error' => 'Sponsor contract is not active'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fee = $sponsor->calculateEarlyTerminationFee();
        $sponsor->setEarlyTerminationFee($fee);
        $sponsor->setStatus(SponsorStatus::EARLY_TERMINATED);

        $this->sponsorRepository->getEntityManager()->flush();

        return $this->json([
            'status'             => 'terminated',
            'earlyTerminationFee' => $fee,
        ]);
    }

    private function serializeInvestor(Investor $investor): array
    {
        return [
            'id'               => (string) $investor->getId(),
            'company'          => $investor->getCompany(),
            'tier'             => $investor->getTier()->value,
            'percentageOwned'  => $investor->getPercentageOwned(),
            'investmentAmount' => $investor->getInvestmentAmount(),
            'buybackPrice'     => $investor->getBuybackPrice(),
            'investedAt'       => $investor->getInvestedAt()?->format(\DateTimeInterface::ATOM),
            'lastPayoutAt'     => $investor->getLastPayoutAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeSponsor(Sponsor $sponsor): array
    {
        return [
            'id'                      => (string) $sponsor->getId(),
            'company'                 => $sponsor->getCompany(),
            'size'                    => $sponsor->getSize()->value,
            'status'                  => $sponsor->getStatus()->value,
            'monthlyPayment'          => $sponsor->getMonthlyPayment(),
            'contractStartDate'       => $sponsor->getContractStartDate()?->format(\DateTimeInterface::ATOM),
            'contractEndDate'         => $sponsor->getContractEndDate()?->format(\DateTimeInterface::ATOM),
            'remainingMonths'         => $sponsor->getRemainingMonths(),
            'remainingValue'          => $sponsor->getRemainingValue(),
            'reputationMinThreshold'  => $sponsor->getReputationMinThreshold(),
            'reputationBonusThreshold' => $sponsor->getReputationBonusThreshold(),
            'bonusMultiplier'         => $sponsor->getBonusMultiplier(),
            'earlyTerminationFee'     => $sponsor->getEarlyTerminationFee(),
        ];
    }
}
