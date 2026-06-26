<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

class VerificationAwareAuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly AuthenticationSuccessHandlerInterface $decorated,
        private readonly EntityManagerInterface $em,
    ) {}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();

        if ($user instanceof User && !$user->isVerified()) {
            return new JsonResponse([
                'error'  => 'email_not_verified',
                'userId' => $user->getId()->toRfc4122(),
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user instanceof User) {
            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return $this->decorated->onAuthenticationSuccess($request, $token);
    }
}
