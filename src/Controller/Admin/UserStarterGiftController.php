<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\StartingGrantService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Gift starter balance" on the admin user page: tops the account's starting grant up to the
 * maximum of the configured range (StartingGrantService::giftToMax()). Written at most once per
 * account by the ledger's own idempotency key, so a double click or a resubmit pays nothing.
 *
 * Only ever redirects, so it can target the plain route (same exemption as
 * admin_user_kill_session; see src/Controller/Admin/CLAUDE.md).
 */
#[IsGranted('ROLE_ADMIN')]
class UserStarterGiftController extends AbstractController
{
    public function __construct(
        private readonly StartingGrantService $startingGrant,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {}

    #[Route('/admin/users/{id}/gift-starter-balance', name: 'admin_user_gift_starter_balance', methods: ['POST'])]
    public function gift(Request $request, User $user): Response
    {
        $back = $this->redirect(
            $this->adminUrlGenerator
                ->setController(UserCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($user->getId())
                ->generateUrl(),
        );

        if (!$this->isCsrfTokenValid('gift_starter_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token. Nothing was gifted.');

            return $back;
        }

        $preview = $this->startingGrant->giftPreview($user);

        if ($preview['giftPence'] <= 0) {
            $this->addFlash('warning', sprintf(
                '%s already has a starting grant of £%s (max £%s). Nothing was gifted.',
                $user->getEmail(),
                number_format($preview['grantedPence'] / 100, 2),
                number_format($preview['maxPence'] / 100, 2),
            ));

            return $back;
        }

        $entry = $this->startingGrant->giftToMax($user);

        if ($entry === null || $entry->getAmountPence() !== $preview['giftPence']) {
            // append() handed back an existing row: a gift was already written for this account
            // (the max has been raised since). The ledger key allows one gift, by design.
            $this->addFlash('warning', 'This account has already had its starter gift. Nothing more was paid; use a ledger adjustment if more is needed.');

            return $back;
        }

        $this->addFlash('success', sprintf(
            'Gifted £%s to %s. Starting grant is now £%s; owner balance £%s.',
            number_format($entry->getAmountPence() / 100, 2),
            $user->getEmail(),
            number_format(($preview['grantedPence'] + $entry->getAmountPence()) / 100, 2),
            number_format($entry->getBalanceAfterPence() / 100, 2),
        ));

        return $back;
    }
}
