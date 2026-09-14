<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BetaRequest;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class BetaRequestInviteController extends AbstractController
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
        private readonly EntityManagerInterface    $em,
    ) {}

    #[Route('/admin/beta-requests/{id}/send-invite', name: 'admin_beta_request_send_invite', methods: ['GET'])]
    public function sendInvite(Request $request, BetaRequest $betaRequest): RedirectResponse
    {
        $tokenId = 'beta_request_invite_' . $betaRequest->getId();
        if (!$this->isCsrfTokenValid($tokenId, $request->query->get('_token'))) {
            $this->addFlash('danger', 'Invalid or expired link — please try again.');
            return $this->redirectToIndex();
        }

        $this->emailVerificationService->sendBetaInviteEmail($betaRequest->getEmail());
        $betaRequest->markInvited();
        $this->em->flush();

        $this->addFlash('success', sprintf('Beta invite email sent to %s.', $betaRequest->getEmail()));

        return $this->redirectToIndex();
    }

    private function redirectToIndex(): RedirectResponse
    {
        return $this->redirect($this->generateUrl('admin', [
            'crudControllerFqcn' => BetaRequestCrudController::class,
            'crudAction'         => 'index',
        ]));
    }
}
