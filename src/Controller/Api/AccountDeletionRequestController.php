<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\DeletionRequest;
use App\Entity\User;
use App\Enum\DeletionRequestStatus;
use App\Repository\ClubRepository;
use App\Repository\DeletionRequestRepository;
use App\Service\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, browser-reachable account deletion — the Google Play / iOS requirement.
 *
 * Distinct from `POST /api/account/delete`, which authenticates with a JWT and so
 * is only usable from inside the app. This one authenticates with email +
 * password, because a store reviewer must be able to delete an account from a
 * plain web page with nothing installed.
 *
 * Deletion is immediate on valid credentials, not queued: a queue would oblige us
 * to publish and meet a turnaround, and every request would need manual work.
 * The DeletionRequest row is the audit trail instead.
 */
#[Route('/api/account')]
class AccountDeletionRequestController extends AbstractController
{
    /**
     * Failed credential attempts allowed from one IP per window.
     *
     * Only failures count — see DeletionRequestRepository::countRecentFailuresByIp().
     * Ten wrong passwords in fifteen minutes is comfortably past honest mistyping
     * and nowhere near enough to brute-force anything.
     */
    private const RATE_LIMIT = 10;

    private const RATE_WINDOW = '-15 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly DeletionRequestRepository $deletionRequestRepository,
        private readonly ClubRepository $clubRepository,
        private readonly AccountDeletionService $accountDeletionService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/delete-request', name: 'api_account_delete_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        $email    = strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');
        $confirm  = strtoupper(trim((string) $request->request->get('confirm')));
        $ip       = $request->getClientIp();

        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'success' => false,
                'code'    => 'invalid_email',
                'message' => 'Enter a valid email address.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The typed-DELETE gate is enforced server-side too. A client-only check
        // would make the confirmation theatre rather than a safeguard.
        if ($confirm !== 'DELETE') {
            return $this->json([
                'success' => false,
                'code'    => 'not_confirmed',
                'message' => 'Type DELETE to confirm.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($ip !== null && $this->deletionRequestRepository->countRecentFailuresByIp($ip, new \DateTimeImmutable(self::RATE_WINDOW)) >= self::RATE_LIMIT) {
            return $this->json([
                'success'    => false,
                'code'       => 'rate_limited',
                'message'    => 'Too many attempts. Try again later.',
                'retryAfter' => 900,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Checked before any credential work: guest accounts are device-bound,
        // carry a synthetic address and have no password the user ever set, so
        // no amount of retrying this form will ever work for them. They must be
        // sent to the in-app button instead of left guessing.
        if (User::isGuestEmail($email)) {
            $this->record($email, DeletionRequestStatus::REJECTED_GUEST, $ip);

            return $this->json([
                'success' => false,
                'code'    => 'guest_account',
                'message' => 'This is a guest account created on your device. Delete it from inside the app: Settings → Delete Account.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        // Hash against a throwaway user when the account is unknown so a missing
        // account and a wrong password cost the same time. Skipping this turns the
        // form into an account-enumeration oracle: anyone could probe whether an
        // address is registered by timing the response. The message is identical
        // for both cases for the same reason — the precedent is
        // SyncController::forgotPassword(), which always answers neutrally.
        $valid = $user !== null
            ? $this->passwordHasher->isPasswordValid($user, $password)
            : $this->burnTimingBudget($password);

        if ($user === null || !$valid) {
            $this->record($email, DeletionRequestStatus::REJECTED_INVALID_CREDENTIALS, $ip);

            return $this->json([
                'success' => false,
                'code'    => 'invalid_credentials',
                'message' => 'Those details do not match an account. Check the email and password and try again.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $clubCount = count($this->clubRepository->findAllByUser($user));

        try {
            $this->accountDeletionService->deleteAccount($user);
        } catch (\Throwable $e) {
            $this->logger->error('Web account deletion failed', ['email' => $email, 'exception' => $e]);
            $this->record($email, DeletionRequestStatus::FAILED, $ip, $e->getMessage());

            return $this->json([
                'success' => false,
                'code'    => 'deletion_failed',
                'message' => 'Something went wrong deleting the account. Email admin@buildmyclub.co.uk and we will finish it manually.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->record($email, DeletionRequestStatus::COMPLETED, $ip, null, $clubCount);

        return $this->json([
            'success'      => true,
            'clubsDeleted' => $clubCount,
            'message'      => 'Your account and all associated club data have been permanently deleted.',
        ]);
    }

    /**
     * Records the attempt on its own EntityManager flush.
     *
     * Kept separate from the deletion transaction on purpose: AccountDeletionService
     * wraps its work in a transaction, and a failure there closes the EntityManager,
     * so an audit row enlisted in that same unit of work would be rolled back
     * precisely in the FAILED case where it matters most.
     */
    private function record(
        string $email,
        DeletionRequestStatus $status,
        ?string $ip,
        ?string $failureReason = null,
        int $clubsDeleted = 0,
    ): void {
        try {
            $record = new DeletionRequest($email, $status, $ip);
            $record->setFailureReason($failureReason)->setClubsDeleted($clubsDeleted);

            // AccountDeletionService wraps its work in a transaction, and Doctrine
            // closes the EntityManager on a failed flush. Reset it first or the
            // FAILED row — the one row an operator actually needs — cannot be written.
            if (!$this->em->isOpen()) {
                $this->registry->resetManager();
            }

            $em = $this->registry->getManager();
            $em->persist($record);
            $em->flush();
        } catch (\Throwable $e) {
            // Never let audit-logging failure mask the actual outcome.
            $this->logger->error('Could not record deletion request', ['email' => $email, 'exception' => $e]);
        }
    }

    /**
     * Spend roughly one password verification's worth of time on an unknown
     * account, so response timing does not reveal whether the email exists.
     */
    private function burnTimingBudget(string $password): bool
    {
        // hashPassword(), not isPasswordValid(): the latter reads getPassword(),
        // and a freshly constructed User has that typed property uninitialized,
        // which is a fatal rather than a false. Hashing runs the same KDF once,
        // so it costs the same wall-clock time as the verification it stands in for.
        $this->passwordHasher->hashPassword(new User('timing@guard.invalid'), $password);

        return false;
    }
}
