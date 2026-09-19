<?php

namespace App\Controller\Api;

use App\Dto\RegisterDeviceTokenRequest;
use App\Entity\User;
use App\Entity\UserDevice;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * FCM device-token registration for push notifications — see PushNotificationService and
 * .context/stages/04_interfaces/output/push-notifications.md.
 */
#[Route('/api/device-tokens')]
#[IsGranted('ROLE_CLUB')]
class DeviceTokenController extends AbstractController
{
    public function __construct(
        private readonly UserDeviceRepository $deviceRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Upserts by deviceToken: a token identifies one app installation, not an account, so
     * re-registering an already-known token under a different authenticated user (e.g.
     * logging into a different club on the same phone) reassigns it here rather than erroring.
     */
    #[Route('', name: 'api_device_tokens_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterDeviceTokenRequest $dto): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $device = $this->deviceRepository->findByDeviceToken($dto->deviceToken);

        if ($device === null) {
            $device = new UserDevice($user, $dto->deviceToken, $dto->platform, $dto->deviceId);
            $this->em->persist($device);
        } else {
            $device->setUser($user)
                ->setPlatform($dto->platform)
                ->setDeviceId($dto->deviceId)
                ->touch();
        }

        $this->em->flush();

        return $this->json(['success' => true], Response::HTTP_CREATED);
    }

    /** Explicit removal on client logout — the api firewall is stateless JWT, so there is no server session to invalidate this from. */
    #[Route('/{deviceToken}', name: 'api_device_tokens_delete', methods: ['DELETE'], requirements: ['deviceToken' => '.+'])]
    public function delete(string $deviceToken): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $device = $this->deviceRepository->findByDeviceToken($deviceToken);

        // Deleting someone else's token is a no-op rather than a 403/404 leak of whether the
        // token exists at all.
        if ($device !== null && $device->getUser()->getId()->equals($user->getId())) {
            $this->em->remove($device);
            $this->em->flush();
        }

        return $this->json(['success' => true]);
    }
}
