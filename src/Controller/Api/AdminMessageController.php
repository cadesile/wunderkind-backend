<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\MessageAckRequest;
use App\Entity\AdminMessage;
use App\Entity\User;
use App\Enum\MessageDeliveryStatus;
use App\Repository\AdminMessageRepository;
use App\Service\AdminMessageService;
use App\Service\ClubResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Server-driven announcements: operator-authored messages polled by the client.
 *
 * The response shape is a contract with the client's TS types — camelCase throughout, matching
 * every other endpoint here. Do not rename keys without updating the frontend; see
 * docs/api/server-driven-messaging.md.
 *
 * Targeting is evaluated against the caller's Club (reputation, league tier, country and week
 * only exist there), but delivery state is recorded against the User — see MessageDelivery.
 * Guests and registered accounts are both plain User rows and are served identically; nothing
 * here inspects verification status or the synthetic guest email domain.
 *
 * Deliberately NOT ETag-cached, unlike ArchetypeController: this response is per-caller and
 * changes on every acknowledgement, so a 304 would suppress a message the client never saw.
 */
#[Route('/api/messages')]
#[IsGranted('ROLE_CLUB')]
class AdminMessageController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver $clubResolver,
        private readonly AdminMessageRepository $messageRepository,
        private readonly AdminMessageService $adminMessageService,
    ) {}

    #[Route('/pending', name: 'api_messages_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found'], Response::HTTP_NOT_FOUND);
        }

        $messages = $this->adminMessageService->findPendingForClub($club);

        return $this->json([
            'messages' => array_map($this->serializeMessage(...), $messages),
        ]);
    }

    #[Route('/{id}/ack', name: 'api_messages_ack', methods: ['POST'])]
    public function ack(
        string $id,
        #[MapRequestPayload] MessageAckRequest $dto,
    ): JsonResponse {
        if ($dto->status === MessageDeliveryStatus::PENDING) {
            return $this->json(
                ['error' => 'invalid_status', 'message' => 'status must be "displayed" or "dismissed".'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var User $user */
        $user = $this->getUser();

        // No club lookup: deliveries key on User, so a player who has just deleted or replaced
        // their club can still retire a message they have already seen.
        //
        // Guard before find(): Doctrine would raise a conversion error on a malformed uuid
        // against the uuid-typed primary key, surfacing as a 500 instead of a 404.
        $message = Uuid::isValid($id) ? $this->messageRepository->find($id) : null;

        if ($message === null) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $this->adminMessageService->acknowledge($user, $message, $dto->status);

        return $this->json(['success' => true]);
    }

    /** @return array<string, mixed> */
    private function serializeMessage(AdminMessage $message): array
    {
        return [
            'id'          => $message->getId()->toRfc4122(),
            'title'       => $message->getTitle(),
            'bodyHtml'    => $message->getBodyHtml(),
            'priority'    => $message->getPriority()->value,
            'displayType' => $message->getDisplayType()->value,
            'createdAt'   => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
