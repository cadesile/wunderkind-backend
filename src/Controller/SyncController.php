<?php

namespace App\Controller;

use App\Dto\SyncRequest;
use App\Entity\Academy;
use App\Entity\User;
use App\Service\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

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
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (empty($data['email']) || empty($data['password']) || empty($data['academyName'])) {
            return $this->json(['error' => 'email, password and academyName are required.'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $em->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existing !== null) {
            return $this->json(['error' => 'Email already registered.'], Response::HTTP_CONFLICT);
        }

        $user = new User($data['email']);
        $user->setPassword($hasher->hashPassword($user, $data['password']));
        $user->setRoles([User::ROLE_ACADEMY]);

        $academy = new Academy($data['academyName'], $user);
        $em->persist($user);
        $em->persist($academy);
        $em->flush();

        return $this->json([
            'id'          => (string) $user->getId(),
            'email'       => $user->getEmail(),
            'academyName' => $academy->getName(),
        ], Response::HTTP_CREATED);
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
