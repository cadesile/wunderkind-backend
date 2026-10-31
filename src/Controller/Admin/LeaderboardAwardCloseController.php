<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Service\Leaderboard\LeaderboardPeriodCloseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Close now" for one award definition. There is no crontab under Lando, so this is how an
 * admin — and the developer — exercises the close job by hand.
 *
 * POST only and CSRF-guarded: closing a period prunes live rows, pays prizes and sends
 * inbox mail, which is not something a crawler or a stray GET should be able to trigger.
 *
 * The redirect goes to admin_leaderboard_award_definition_detail, one of EasyAdmin's own
 * pretty CRUD routes — already wired to build the full crud/entity context on a direct
 * match. Only routes EasyAdmin does not know how to contextualise (like this action's own)
 * need the /admin?routeName=... forwarding trick; see src/Controller/Admin/CLAUDE.md.
 */
class LeaderboardAwardCloseController extends AbstractController
{
    public function __construct(
        private readonly LeaderboardPeriodCloseService $closeService,
    ) {}

    #[Route('/admin/leaderboard-award/{definition}/close-now', name: 'admin_leaderboard_award_close_now', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function closeNow(LeaderboardAwardDefinition $definition, Request $request): Response
    {
        $detailUrl = $this->generateUrl('admin_leaderboard_award_definition_detail', [
            'entityId' => (string) $definition->getId(),
        ]);

        if (!$this->isCsrfTokenValid('leaderboard_award_close_now', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($detailUrl);
        }

        $now   = new \DateTimeImmutable();
        $force = $request->request->getBoolean('force');

        $dueKey = $this->closeService->dueKeyFor($definition, $now, $force);

        if ($dueKey === null) {
            $this->addFlash('warning', sprintf(
                'Nothing due for %s. Its current period (%s) has not ended yet — tick "close the open period early" to close it anyway.',
                (string) $definition,
                $definition->currentPeriodKey($now),
            ));

            return $this->redirect($detailUrl);
        }

        $outcome = $this->closeService->closePeriod($definition, $dueKey, $now);

        if ($outcome['result'] === 'claim not won') {
            $this->addFlash('warning', sprintf(
                '%s is already being closed, or has already been closed, by another process.',
                $dueKey,
            ));

            return $this->redirect($detailUrl);
        }

        if (str_starts_with((string) $outcome['result'], 'skipped')) {
            $this->addFlash('warning', sprintf('%s closed with nothing to archive — no eligible club was on the board.', $dueKey));

            return $this->redirect($detailUrl);
        }

        $this->addFlash('success', sprintf(
            '%s closed: %d eligible club(s), %d row(s) archived, %d award(s) delivered, %d live row(s) pruned.',
            $dueKey,
            $outcome['eligible'] ?? 0,
            $outcome['archived'] ?? 0,
            $outcome['awarded'] ?? 0,
            $outcome['pruned'] ?? 0,
        ));

        return $this->redirect($detailUrl);
    }
}
