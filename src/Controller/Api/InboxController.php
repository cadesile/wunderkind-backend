<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\AcademyRepository;
use App\Repository\InboxMessageRepository;
use App\Service\InboxService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/inbox')]
#[IsGranted('ROLE_ACADEMY')]
class InboxController extends AbstractController
{
    public function __construct(
        private readonly AcademyRepository     $academyRepository,
        private readonly InboxMessageRepository $inboxMessageRepository,
        private readonly InboxService          $inboxService,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found'], Response::HTTP_NOT_FOUND);
        }

        $messages    = $this->inboxMessageRepository->findByAcademy($academy);
        $unreadCount = $this->inboxMessageRepository->countUnread($academy);

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
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->inboxMessageRepository->findOneByAcademyAndId($academy, $id);

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
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->inboxMessageRepository->findOneByAcademyAndId($academy, $id);

        if ($message === null) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $this->inboxService->acceptMessage($message, $user);

        return $this->json(['status' => 'accepted']);
    }

    #[Route('/{id}/reject', methods: ['POST'])]
    public function reject(string $id): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->inboxMessageRepository->findOneByAcademyAndId($academy, $id);

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
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->inboxMessageRepository->findOneByAcademyAndId($academy, $id);

        if ($message === null) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $message->markAsRead();
        // Flush via the InboxService's EM
        $this->inboxMessageRepository->getEntityManager()->flush();

        return $this->json(['status' => 'read']);
    }

    private function serializeMessage(\App\Entity\InboxMessage $message): array
    {
        return [
            'id'                => (string) $message->getId(),
            'senderType'        => $message->getSenderType()->value,
            'senderName'        => $message->getSenderName(),
            'subject'           => $message->getSubject(),
            'body'              => $message->getBody(),
            'status'            => $message->getStatus()->value,
            'offerData'         => $message->getOfferData(),
            'relatedEntityType' => $message->getRelatedEntityType(),
            'relatedEntityId'   => $message->getRelatedEntityId(),
            'createdAt'         => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'respondedAt'       => $message->getRespondedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
