<?php

namespace App\Controller;

use App\Dto\SyncRequest;
use App\Entity\User;
use App\Repository\EmailVerificationRepository;
use App\Service\EmailVerificationService;
use App\Service\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route('/api')]
class SyncController extends AbstractController
{
    /**
     * POST /api/login — handled by the json_login firewall authenticator.
     * This method is never reached; the security layer intercepts first.
     */
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('This method should not be reached.');
    }

    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        EmailVerificationService $verificationService,
    ): JsonResponse {
        $data     = json_decode($request->getContent(), true);
        $email    = trim(strtolower($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'invalid_email'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (strlen($password) < 8) {
            return $this->json([
                'error'   => 'password_too_short',
                'message' => 'Password must be at least 8 characters',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing !== null) {
            if (!$existing->isVerified()) {
                // Resend code — allows retry if the verification email was lost
                $verificationService->sendVerificationEmail($existing);
                return $this->json([
                    'message' => 'verification_resent',
                    'userId'  => $existing->getId()->toRfc4122(),
                ], Response::HTTP_OK);
            }
            return $this->json(['error' => 'email_taken'], Response::HTTP_CONFLICT);
        }

        $user = new User($email);
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->setRoles([User::ROLE_CLUB]);
        // isVerified defaults to false

        if (!empty($data['manager']) && is_array($data['manager'])) {
            $user->setManagerProfile($data['manager']);
        }

        $em->persist($user);
        $em->flush();

        $verificationService->sendVerificationEmail($user);

        return $this->json([
            'message' => 'verification_sent',
            'userId'  => $user->getId()->toRfc4122(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/verify-email', name: 'api_verify_email', methods: ['POST'])]
    public function verifyEmail(
        Request $request,
        EntityManagerInterface $em,
        EmailVerificationService $verificationService,
        JWTTokenManagerInterface $jwtManager,
        EventDispatcherInterface $eventDispatcher,
    ): JsonResponse {
        $data   = json_decode($request->getContent(), true);
        $userId = $data['userId'] ?? '';
        $code   = trim($data['code'] ?? '');

        $user = $em->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            return $this->json(['error' => 'user_not_found'], Response::HTTP_NOT_FOUND);
        }

        $result = $verificationService->verifyCode($user, $code);

        if ($result !== 'ok') {
            return match ($result) {
                'expired'      => $this->json(['error' => 'expired'], Response::HTTP_UNPROCESSABLE_ENTITY),
                'max_attempts' => $this->json(['error' => 'max_attempts'], Response::HTTP_TOO_MANY_REQUESTS),
                default        => $this->json(['error' => 'invalid_code'], Response::HTTP_UNPROCESSABLE_ENTITY),
            };
        }

        // Dispatch AuthenticationSuccessEvent so gesdinet attaches refresh_token,
        // producing exactly the same response shape as POST /api/login.
        $token  = $jwtManager->create($user);
        $response = new JsonResponse();
        $event  = new AuthenticationSuccessEvent(['token' => $token], $user, $response);
        $eventDispatcher->dispatch($event, Events::AUTHENTICATION_SUCCESS);

        return new JsonResponse($event->getData(), Response::HTTP_OK);
    }

    #[Route('/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function resendVerification(
        Request $request,
        EntityManagerInterface $em,
        EmailVerificationService $verificationService,
        EmailVerificationRepository $verificationRepo,
    ): JsonResponse {
        $data   = json_decode($request->getContent(), true);
        $userId = $data['userId'] ?? '';

        $user = $em->getRepository(User::class)->find($userId);
        if (!$user instanceof User || $user->isVerified()) {
            return $this->json(['error' => 'invalid_request'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Rate limit: reject if an active verification was created in the last 60 seconds
        $recent = $verificationRepo->findActiveForUser($user);
        if ($recent !== null && $recent->getCreatedAt() > new \DateTimeImmutable('-60 seconds')) {
            return $this->json([
                'error'       => 'rate_limited',
                'retryAfter'  => 60,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $verificationService->sendVerificationEmail($user);

        return $this->json([
            'message'   => 'code_resent',
            'expiresIn' => 900,
        ], Response::HTTP_OK);
    }

    #[Route('/sync', name: 'api_sync', methods: ['POST'])]
    public function sync(
        #[MapRequestPayload] SyncRequest $syncRequest,
        SyncService $syncService,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $result = $syncService->process($user, $syncRequest);

        $status = $result['accepted'] ? Response::HTTP_OK : Response::HTTP_CONFLICT;

        return $this->json($result, $status);
    }
}
