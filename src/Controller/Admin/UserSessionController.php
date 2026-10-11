<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\SessionRevocationService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class UserSessionController extends AbstractController
{
    public function __construct(
        private readonly SessionRevocationService $sessionRevocation,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {}

    /**
     * Signs a user out of every device. POST + CSRF because it is a real,
     * user-visible side effect, not a view.
     *
     * The redirect goes through EasyAdmin's entry point rather than straight to
     * the CRUD route — see src/Controller/Admin/CLAUDE.md; a plain
     * redirectToRoute() leaves the `ea` Twig context unset and blows up with an
     * "i18n on null" error on the page it lands on.
     */
    #[Route('/admin/users/{id}/kill-session', name: 'admin_user_kill_session', methods: ['POST'])]
    public function killSession(Request $request, User $user): Response
    {
        $backToDetail = $this->redirect(
            $this->adminUrlGenerator
                ->setController(UserCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($user->getId())
                ->generateUrl(),
        );

        if (!$this->isCsrfTokenValid('kill_session_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token — no sessions were revoked.');

            return $backToDetail;
        }

        $deleted = $this->sessionRevocation->revokeAllSessions($user);

        $this->addFlash('success', sprintf(
            'Signed %s out everywhere. %d refresh token%s revoked; existing access tokens rejected from the next request.',
            $user->getUserIdentifier(),
            $deleted,
            $deleted === 1 ? '' : 's',
        ));

        return $backToDetail;
    }
}
