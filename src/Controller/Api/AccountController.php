<?php

namespace App\Controller\Api;

use App\Entity\DeletionRequest;
use App\Entity\User;
use App\Enum\DeletionRequestStatus;
use App\Repository\ClubRepository;
use App\Service\AccountDeletionService;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/account')]
class AccountController extends AbstractController
{
    #[Route('/delete', name: 'api_account_delete', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function delete(
        Request $request,
        AccountDeletionService $accountDeletionService,
        ClubRepository $clubRepository,
        ManagerRegistry $registry,
        LoggerInterface $logger,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $ip   = $request->getClientIp();

        // Counted before deletion runs — afterwards the clubs (and the count) are gone.
        $clubCount = count($clubRepository->findAllByUser($user));

        try {
            $accountDeletionService->deleteAccount($user);
        } catch (\Throwable $e) {
            $logger->error('Account deletion failed', ['userId' => (string) $user->getId(), 'exception' => $e]);
            $this->record($registry, $logger, $user->getUserIdentifier(), DeletionRequestStatus::FAILED, $ip, $e->getMessage());

            return $this->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->record($registry, $logger, $user->getUserIdentifier(), DeletionRequestStatus::COMPLETED, $ip, null, $clubCount);

        return $this->json(['success' => true]);
    }

    /**
     * Same audit trail as the web deletion form (`AccountDeletionRequestController::record()`),
     * on its own flush for the same reason: AccountDeletionService wraps its work in a
     * transaction, and Doctrine closes the EntityManager on a failed flush, so an audit row
     * enlisted in that same unit of work would be rolled back precisely in the FAILED case
     * where it matters most.
     */
    private function record(
        ManagerRegistry $registry,
        LoggerInterface $logger,
        string $email,
        DeletionRequestStatus $status,
        ?string $ip,
        ?string $failureReason = null,
        int $clubsDeleted = 0,
    ): void {
        try {
            $record = new DeletionRequest($email, $status, $ip);
            $record->setFailureReason($failureReason)->setClubsDeleted($clubsDeleted);

            $em = $registry->getManager();
            if (!$em->isOpen()) {
                $registry->resetManager();
                $em = $registry->getManager();
            }

            $em->persist($record);
            $em->flush();
        } catch (\Throwable $e) {
            // Never let audit-logging failure mask the actual outcome.
            $logger->error('Could not record deletion request', ['email' => $email, 'exception' => $e]);
        }
    }
}
