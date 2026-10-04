<?php

namespace App\Controller\Api;

use App\Dto\RegisterDeviceTokenRequest;
use App\Entity\User;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\UuidV7;

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
     *
     * Raw `INSERT ... ON CONFLICT`, not find-then-persist: the app fires this call more than
     * once in quick succession in practice (push-permission callback + token-refresh listener,
     * retry on a slow network), and a plain "find, then insert if missing" has a real race
     * between the SELECT and the INSERT — two concurrent requests for the same new token can
     * both see "not found" and both attempt to insert, and the loser crashes with a
     * UniqueConstraintViolationException instead of the no-op/reassign this is meant to be.
     * Same reasoning as AdminMessageService::acknowledge()'s own ON CONFLICT upsert — catching
     * the exception from a failed flush() doesn't work either, since Doctrine closes the
     * EntityManager after that.
     */
    #[Route('', name: 'api_device_tokens_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterDeviceTokenRequest $dto): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $now  = new \DateTimeImmutable();

        $this->em->getConnection()->executeStatement(
            <<<'SQL'
            INSERT INTO user_device (id, user_id, device_token, platform, device_id, last_active_at, created_at)
            VALUES (:id, :user, :deviceToken, :platform, :deviceId, :now, :now)
            ON CONFLICT (device_token) DO UPDATE
                SET user_id        = EXCLUDED.user_id,
                    platform       = EXCLUDED.platform,
                    device_id      = EXCLUDED.device_id,
                    last_active_at = EXCLUDED.last_active_at
            SQL,
            [
                'id'           => (new UuidV7())->toRfc4122(),
                'user'         => $user->getId()->toRfc4122(),
                'deviceToken'  => $dto->deviceToken,
                'platform'     => $dto->platform->value,
                'deviceId'     => $dto->deviceId,
                'now'          => $now->format('Y-m-d H:i:s'),
            ],
        );

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
