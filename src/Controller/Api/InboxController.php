<?php

namespace App\Controller\Api;

use App\Entity\InboxMessage;
use App\Entity\User;
use App\Enum\MessageSenderType;
use App\Enum\MessageStatus;
use App\Repository\InboxMessageRepository;
use App\Service\InboxService;
use App\Service\ClubResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/inbox')]
#[IsGranted('ROLE_CLUB')]
class InboxController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver       $clubResolver,
        private readonly InboxMessageRepository $inboxMessageRepository,
        private readonly InboxService          $inboxService,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found'], Response::HTTP_NOT_FOUND);
        }

        $messages    = $this->inboxMessageRepository->findByClub($club);
        $unreadCount = $this->inboxMessageRepository->countUnread($club);

        return $this->json([
            'unreadCount' => $unreadCount,
            'messages'    => array_map($this->serializeMessage(...), $messages),
        ]);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found'], Response::HTTP_NOT_FOUND);
        }

        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->inboxMessageRepository->findOneByClubAndId($club, $id);

        if ($message === null) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $message->markAsRead();
        $this->inboxMessageRepository->getEntityManager()->flush();

        return $this->json($this->serializeMessage($message));
    }

    #[Route('/{id}/accept', methods: ['POST'])]
    public function accept(string $id): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found'], Response::HTTP_NOT_FOUND);
        }

        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->inboxMessageRepository->findOneByClubAndId($club, $id);

        if ($message === null) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $appliedEffects = $this->inboxService->acceptMessage($message, $user);
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'already_processed') {
                return $this->json([
                    'error'   => 'already_processed',
                    'message' => 'Message has already been processed.',
                ], Response::HTTP_CONFLICT);
            }
            throw $e;
        }

        return $this->json([
            'status'         => 'accepted',
            'appliedEffects' => $appliedEffects,
            'club'           => [
                'clubId'     => (string) $club->getId(),
                'balance'    => $club->getBalance(),
                'reputation' => $club->getReputation(),
            ],
        ]);
    }

    #[Route('/{id}/reject', methods: ['POST'])]
    public function reject(string $id): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found'], Response::HTTP_NOT_FOUND);
        }

        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->inboxMessageRepository->findOneByClubAndId($club, $id);

        if ($message === null) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $this->inboxService->rejectMessage($message);

        return $this->json(['status' => 'rejected']);
    }

    #[Route('/{id}/read', methods: ['POST'])]
    public function markRead(string $id): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found'], Response::HTTP_NOT_FOUND);
        }

        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->inboxMessageRepository->findOneByClubAndId($club, $id);

        if ($message === null) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $message->markAsRead();
        $this->inboxMessageRepository->getEntityManager()->flush();

        return $this->json(['status' => 'read']);
    }

    #[Route('/{id}/dismiss', methods: ['POST'])]
    public function dismiss(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found'], Response::HTTP_NOT_FOUND);
        }

        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->inboxMessageRepository->findOneByClubAndId($club, $id);

        if ($message === null) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $this->inboxService->dismissMessage($message);

        return $this->json(['status' => 'dismissed']);
    }

    public function serializeMessage(InboxMessage $message): array
    {
        $offerData = $message->getOfferData();
        $type      = is_array($offerData) && is_string($offerData['type'] ?? null)
            ? $offerData['type']
            : match ($message->getSenderType()) {
                MessageSenderType::SPONSOR     => 'SPONSOR_OFFER',
                MessageSenderType::INVESTOR    => 'INVESTOR_OFFER',
                MessageSenderType::COMPETITION => 'COMPETITION_REWARD',
                MessageSenderType::FRIENDLY    => 'FRIENDLY_NOTIFICATION',
                default                        => 'SYSTEM',
            };

        $link = null;
        if (is_array($offerData) && is_array($offerData['link'] ?? null)) {
            $link = $offerData['link'];
        } elseif (is_array($offerData) && is_string($offerData['competitionId'] ?? null)) {
            $link = ['kind' => 'competition', 'id' => $offerData['competitionId']];
        } elseif ($message->getRelatedEntityId() !== null) {
            $link = [
                'kind' => strtolower((string) $message->getRelatedEntityType()),
                'id'   => $message->getRelatedEntityId(),
            ];
        }

        $hasEffects  = is_array($offerData) && !empty($offerData['effects']);
        $isOfferType = in_array($message->getSenderType(), [MessageSenderType::SPONSOR, MessageSenderType::INVESTOR], true);
        $isPending   = in_array($message->getStatus(), [MessageStatus::UNREAD, MessageStatus::READ], true);

        return [
            'id'                => (string) $message->getId(),
            'senderType'        => $message->getSenderType()->value,
            'senderName'        => $message->getSenderName(),
            'subject'           => $message->getSubject(),
            'body'              => $message->getBody(),
            'type'              => $type,
            'link'              => $link,
            'requiresAccept'    => $isPending && ($hasEffects || $isOfferType),
            'status'            => $message->getStatus()->value,
            'offerData'         => $offerData,
            'relatedEntityType' => $message->getRelatedEntityType(),
            'relatedEntityId'   => $message->getRelatedEntityId(),
            'createdAt'         => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'respondedAt'       => $message->getRespondedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
