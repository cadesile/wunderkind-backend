<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Entity\User;
use App\Service\AccountDeletionService;
use App\Service\Club\ClubScrubService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class DeleteAdminController extends AbstractController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ClubScrubService          $clubScrub,
        private readonly AccountDeletionService    $accountDeletion,
    ) {}

    // ── Club delete ───────────────────────────────────────────────────────────

    #[Route('/admin/clubs/{id}/delete-info', name: 'admin_club_delete_info', methods: ['GET'])]
    public function clubDeleteInfo(Club $club): Response
    {
        $user       = $club->getUser();
        $otherClubs = $user->getClubs()
            ->filter(fn(Club $c) => $c->getId()->toBinary() !== $club->getId()->toBinary())
            ->map(fn(Club $c) => $c->getName())
            ->getValues();

        return $this->json([
            'clubName'   => $club->getName(),
            'userEmail'  => $user->getUserIdentifier(),
            'otherClubs' => $otherClubs,
            'csrfToken'  => $this->csrf->getToken('delete_club_' . $club->getId())->getValue(),
        ]);
    }

    #[Route('/admin/clubs/{id}/delete', name: 'admin_club_delete', methods: ['POST'])]
    public function clubDelete(Request $request, Club $club): Response
    {
        $tokenId = 'delete_club_' . $club->getId();
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin_club_confirm_delete', ['id' => $club->getId()]));
        }

        $deleteUser = (bool) $request->request->get('deleteUser', false);
        $user       = $club->getUser();
        $email      = $user->getUserIdentifier();

        if ($deleteUser) {
            // DELEGATED TO AccountDeletionService, which this branch used to duplicate badly:
            // its own loop over cleanClubForeignKeys() missed four tables, left refresh-token
            // revocation and archive-identity removal to be hand-rolled here, and never
            // invalidated the leaderboard caches the deleted clubs appeared in. One account
            // delete path, with the FK order recorded in one place.
            $this->accountDeletion->deleteAccount($user);

            $this->addFlash('success', sprintf('User "%s" and all associated clubs deleted.', $email));
        } else {
            // Delete only this club; keep the user account.
            //
            // DELEGATED TO ClubScrubService, which is now the one club-only delete path. This
            // action used to carry its own cleanup (`cleanClubForeignKeys()`), and it had
            // diverged: it missed SyncRecord, LeaderboardEntry, SeasonRatingsSnapshot and
            // competition entrants, and — the real defect — it never released an active
            // `plot_claim`. Because `plot_claim.club_id` is ON DELETE SET NULL, deleting the
            // club left a claim still active with a NULL club, and `uq_plot_claim_active_plot`
            // keys on `plot_id` alone, so that plot became PERMANENTLY UNBUYABLE: nothing could
            // claim it and nothing would ever release it.
            //
            // The ledger rows are still kept, detached — the service relies on the same
            // ON DELETE SET NULL for exactly the reason this comment used to give: the balance
            // is a chain of before/after snapshots, so deleting a club's most recent entries
            // steps the surviving owner's balance back to an earlier figure.
            $this->clubScrub->scrub($club);

            $this->addFlash('success', sprintf('Club "%s" deleted. User account kept.', $club->getName()));
        }

        return $this->redirect($this->generateUrl('admin', [
            'crudControllerFqcn' => ClubCrudController::class,
            'crudAction'         => 'index',
        ]));
    }

    // ── User delete ───────────────────────────────────────────────────────────

    #[Route('/admin/users/{id}/delete-info', name: 'admin_user_delete_info', methods: ['GET'])]
    public function userDeleteInfo(User $user): Response
    {
        return $this->json([
            'userEmail' => $user->getUserIdentifier(),
            'clubs'     => $user->getClubs()->map(fn(Club $c) => $c->getName())->getValues(),
            'csrfToken' => $this->csrf->getToken('delete_user_' . $user->getId())->getValue(),
        ]);
    }

    #[Route('/admin/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function userDelete(Request $request, User $user): Response
    {
        $tokenId = 'delete_user_' . $user->getId();
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin_user_confirm_delete', ['id' => $user->getId()]));
        }

        $email = $user->getUserIdentifier();

        // DELEGATED, like the clubDelete() branch above and for the same reasons: this was a
        // third copy of the FK order, and it also left refresh tokens, archive-identity removal
        // and leaderboard-cache invalidation to be hand-rolled per call site.
        $this->accountDeletion->deleteAccount($user);

        $this->addFlash('success', sprintf('User "%s" and all associated clubs deleted.', $email));

        return $this->redirect($this->generateUrl('admin', [
            'crudControllerFqcn' => UserCrudController::class,
            'crudAction'         => 'index',
        ]));
    }

    // ── Shared cleanup ────────────────────────────────────────────────────────

}
