<?php

namespace App\Controller\Api;

use App\Dto\OwnerAvatarRequest;
use App\Entity\User;
use App\Enum\Appearance\AppearanceRole;
use App\Service\Appearance\AppearanceGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sets/updates the real account holder's own owner identity (name,
 * nationality, gender, DOB) and avatar — the single owner profile every club
 * that account creates reads live via `Club::getUser()`, replacing the old
 * per-club `Club::$managerProfile`/ad hoc `User::$managerProfile` blobs.
 */
#[Route('/api/owner-avatar')]
class OwnerAvatarController extends AbstractController
{
    /** Fallback age when dob is null — matches AppearanceLifecycleSubscriber::DEFAULT_STAFF_AGE. */
    private const DEFAULT_OWNER_AGE = 40;

    #[Route('', name: 'api_owner_avatar_set', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function set(
        Request $request,
        #[MapRequestPayload] OwnerAvatarRequest $dto,
        AppearanceGeneratorService $generator,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        // Partial update: only keys actually present in the JSON body are
        // applied — the validated $dto alone can't distinguish "omitted"
        // from "explicitly null", since every property defaults to null.
        $submitted = (array) $request->toArray();

        $identityChanged = false;
        if (array_key_exists('name', $submitted)) {
            $user->setName($dto->name);
        }
        if (array_key_exists('gender', $submitted)) {
            $user->setGender($dto->gender);
        }
        if (array_key_exists('nationality', $submitted)) {
            $user->setNationality($dto->nationality);
            $identityChanged = true;
        }
        if (array_key_exists('dob', $submitted)) {
            $user->setDob($dto->dob !== null ? new \DateTimeImmutable($dto->dob) : null);
            $identityChanged = true;
        }

        if (array_key_exists('avatar', $submitted)) {
            // Client-supplied avatar config — stored verbatim, the app's own
            // avatar builder is the source of truth here.
            $user->setAppearance($dto->avatar);
        } elseif ($identityChanged && $user->getAppearance() === null) {
            // First-time fill only — never silently overwrite an existing
            // saved look just because name/nationality/dob changed later.
            $user->setAppearance($generator->generate(
                (string) $user->getId(),
                AppearanceRole::OWNER,
                $this->ageFromDob($user->getDob()),
                $user->getNationality(),
            ));
        }

        $em->flush();

        return $this->json([
            'name'        => $user->getName(),
            'nationality' => $user->getNationality(),
            'gender'      => $user->getGender(),
            'dob'         => $user->getDob()?->format('Y-m-d'),
            'avatar'      => $user->getAppearance(),
        ]);
    }

    private function ageFromDob(?\DateTimeImmutable $dob): int
    {
        if ($dob === null) {
            return self::DEFAULT_OWNER_AGE;
        }
        return (int) $dob->diff(new \DateTimeImmutable('now'))->y;
    }
}
