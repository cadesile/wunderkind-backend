<?php

namespace App\Controller\Api;

use App\Entity\BetaRequest;
use App\Repository\BetaRequestRepository;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class BetaRequestController extends AbstractController
{
    public function __construct(
        private readonly BetaRequestRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly EmailVerificationService $emailService,
    ) {}

    #[Route('/beta-request', name: 'api_beta_request', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $email = trim((string) $request->request->get('email', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email address.'], Response::HTTP_BAD_REQUEST);
        }

        // If this email already has a verified record, silently succeed — no re-entry needed
        $latest = $this->repo->findLatestByEmail($email);
        if ($latest !== null && $latest->isValid()) {
            return $this->json(['success' => true]);
        }

        // Expire any currently active (unverified) record so the new code is the only valid one
        $active = $this->repo->findActiveByEmail($email);
        if ($active !== null) {
            $active->expire();
        }

        $code     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $betaReq  = new BetaRequest($email, $code);
        $this->em->persist($betaReq);
        $this->em->flush();

        $this->emailService->sendBetaVerificationEmail($email, $code);

        return $this->json(['success' => true]);
    }

    #[Route('/beta-request/verify', name: 'api_beta_request_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $email = trim((string) $request->request->get('email', ''));
        $code  = trim((string) $request->request->get('code', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '') {
            return $this->json(['error' => 'Email and code are required.'], Response::HTTP_BAD_REQUEST);
        }

        $record = $this->repo->findActiveByEmail($email);

        if ($record === null) {
            $latest = $this->repo->findLatestByEmail($email);
            if ($latest !== null && $latest->isLockedOut()) {
                return $this->json(['error' => 'Too many attempts. Please request a new code.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($latest !== null && $latest->isExpired()) {
                return $this->json(['error' => 'Code has expired. Please request a new code.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            return $this->json(['error' => 'No active request found for this email.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($record->isLockedOut()) {
            return $this->json(['error' => 'Too many attempts. Please request a new code.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($record->getCode() !== $code) {
            $record->incrementAttempts();
            $this->em->flush();
            $remaining = 3 - $record->getAttempts();
            return $this->json([
                'error' => $remaining > 0
                    ? "Incorrect code. {$remaining} attempt(s) remaining."
                    : 'Too many attempts. Please request a new code.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $record->markVerified();
        $this->em->flush();

        return $this->json(['success' => true]);
    }
}
