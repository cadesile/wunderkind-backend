<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\MatchResult;
use App\Entity\RefreshToken;
use App\Entity\SeasonRecord;
use App\Entity\SeasonSnapshot;
use App\Entity\Sponsor;
use App\Entity\Transfer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface    $em,
        private readonly CsrfTokenManagerInterface $csrf,
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
            // Delete cleanup for every club the user owns (avoids FK violations on non-cascaded tables)
            foreach ($user->getClubs() as $c) {
                $this->cleanClubForeignKeys($c);
            }
            $this->em->createQueryBuilder()
                ->delete(RefreshToken::class, 'rt')
                ->where('rt.username = :email')
                ->setParameter('email', $email)
                ->getQuery()->execute();

            $this->em->remove($user); // cascade: removes all clubs → club cascades players/staff/etc.
            $this->em->flush();

            $this->addFlash('success', sprintf('User "%s" and all associated clubs deleted.', $email));
        } else {
            // Delete only this club; keep the user account.
            //
            // The club's UserLedger rows are KEPT, detached, because user_ledger.club_id is now
            // nullable with ON DELETE SET NULL. That matters: the balance is a chain of
            // before/after snapshots and getCurrentBalance() reads the latest surviving row, so
            // while these rows were being deleted instead, removing a club that owned the most
            // recent entries stepped the owner's balance back to an earlier figure. The rows
            // stay, the chain stays whole, and the entries simply no longer name a club.
            $this->cleanClubForeignKeys($club);
            $this->em->remove($club); // cascade: removes players, staff, sync records, leaderboard entries, inbox messages
            $this->em->flush();

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

        foreach ($user->getClubs() as $club) {
            $this->cleanClubForeignKeys($club);
        }
        $this->em->createQueryBuilder()
            ->delete(RefreshToken::class, 'rt')
            ->where('rt.username = :email')
            ->setParameter('email', $email)
            ->getQuery()->execute();

        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', sprintf('User "%s" and all associated clubs deleted.', $email));

        return $this->redirect($this->generateUrl('admin', [
            'crudControllerFqcn' => UserCrudController::class,
            'crudAction'         => 'index',
        ]));
    }

    // ── Shared cleanup ────────────────────────────────────────────────────────

    /**
     * Cleans up non-cascaded FK records for a single club before it is removed.
     * Does NOT flush — caller is responsible for flushing after all clubs are processed.
     */
    private function cleanClubForeignKeys(Club $club): void
    {
        // UserLedger is NO LONGER deleted here, and that is a bug fix rather than an adaptation.
        //
        // user_ledger.club_id used to be NOT NULL with a plain no-action FK, so it could neither
        // cascade nor be nulled, and these rows had to be destroyed to let the club go. Because
        // the running balance is a chain of before/after snapshots and getCurrentBalance() reads
        // the latest surviving row, deleting a club's most recent entries rolled the owner's
        // balance back to an earlier figure — real money disappearing from an account whose
        // owner was not being deleted. The column is now nullable with ON DELETE SET NULL, so
        // the rows survive, detached, and the balance chain stays intact.
        foreach ([Transfer::class, MatchResult::class, SeasonRecord::class, SeasonSnapshot::class] as $class) {
            $this->em->createQueryBuilder()
                ->delete($class, 'e')
                ->where('e.club = :club')
                ->setParameter('club', $club)
                ->getQuery()->execute();
        }

        // UserLedger is deliberately NOT in this loop either. Investor and Sponsor need an
        // explicit null-out because their FKs are plain no-action; user_ledger.club_id is
        // ON DELETE SET NULL, so PostgreSQL detaches those rows itself when the club row goes.
        // An extra UPDATE here would be a redundant write over every one of the club's ledger
        // rows, and worse, would imply to the next reader that the FK does not handle it.
        foreach ([Investor::class => 'i', Sponsor::class => 's'] as $class => $alias) {
            $this->em->createQueryBuilder()
                ->update($class, $alias)
                ->set("{$alias}.club", ':null')
                ->where("{$alias}.club = :club")
                ->setParameter('null', null)
                ->setParameter('club', $club)
                ->getQuery()->execute();
        }

        $this->em->createQuery('DELETE FROM App\Entity\Friendly\FriendlyChallenge f WHERE f.challengerClub = :club AND f.acceptorClub IS NULL')
            ->setParameter('club', $club)
            ->execute();
    }
}
