<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\AccountDeletionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/account')]
class AccountController extends AbstractController
{
    #[Route('/delete', name: 'api_account_delete', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function delete(AccountDeletionService $accountDeletionService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $accountDeletionService->deleteAccount($user);
        } catch (\Throwable) {
            return $this->json(['success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['success' => true]);
    }
}
