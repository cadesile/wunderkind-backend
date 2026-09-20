<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Repository\ClubRepository;
use App\Repository\NotificationLogRepository;
use App\Service\Notification\FirebaseConnectionValidator;
use App\Service\Notification\PushNotificationService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manual debug tools for the push-notification pipeline — kept separate from the already-large
 * DashboardController per the "developer tools" precedent it already establishes there
 * (cleanup-entities, generate-leaderboards). Every action here mirrors that controller's own
 * conventions exactly: CSRF-protected POST, `Symfony\Bundle\FrameworkBundle\Console\Application`
 * run in-process with a `BufferedOutput` for anything that shells out to a console command,
 * flash the result, redirect back to the debug page.
 */
#[IsGranted('ROLE_ADMIN')]
class NotificationDebugController extends AbstractController
{
    /** @var array<string, array{title: string, data: array<string, string>}> Synthetic payloads per debug-trigger type — clearly marked as test data so a real device can't mistake them for a genuine event. */
    private const DEBUG_TRIGGERS = [
        'ROUND_DRAWN' => [
            'title' => '[TEST] Round drawn',
            'data'  => ['type' => 'ROUND_DRAWN', 'competitionId' => 'debug-test', 'roundId' => 'debug-test'],
        ],
        'NEW_REGISTRANT' => [
            'title' => '[TEST] New registrant',
            'data'  => ['type' => 'NEW_REGISTRANT', 'competitionId' => 'debug-test'],
        ],
        'MATCH_RESULT' => [
            'title' => '[TEST] Match result',
            'data'  => ['type' => 'MATCH_RESULT', 'competitionId' => 'debug-test', 'roundId' => 'debug-test', 'fixtureId' => 'debug-test'],
        ],
        'ADMIN_MESSAGE' => [
            'title' => '[TEST] Admin message',
            'data'  => ['type' => 'ADMIN_MESSAGE', 'adminMessageId' => 'debug-test'],
        ],
    ];

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly NotificationLogRepository $notificationLogRepository,
        private readonly PushNotificationService $pushNotificationService,
        private readonly FirebaseConnectionValidator $firebaseConnectionValidator,
    ) {}

    #[Route('/admin/notifications/debug', name: 'admin_notification_debug', methods: ['GET'])]
    public function debug(): Response
    {
        return $this->render('admin/notifications/debug.html.twig', [
            'clubs'        => $this->clubRepository->findBy([], ['name' => 'ASC']),
            'recentLogs'   => $this->notificationLogRepository->findBy([], ['createdAt' => 'DESC'], 20),
            'triggerTypes' => array_keys(self::DEBUG_TRIGGERS),
        ]);
    }

    #[Route('/admin/notifications/debug/force-process-queue', name: 'admin_notification_force_process_queue', methods: ['POST'])]
    public function forceProcessQueue(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('notification_force_process_queue', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_notification_debug']));
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input  = new ArrayInput([
            'command'          => 'messenger:consume',
            'receivers'        => ['async'],
            '--time-limit'     => 20,
            '--limit'          => 200,
            '--no-interaction' => true,
        ]);
        $output = new BufferedOutput();
        $application->run($input, $output);

        $this->addFlash('success', 'Queue processed.');
        $this->addFlash('info', nl2br(htmlspecialchars(trim($output->fetch()))));

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_notification_debug']));
    }

    #[Route('/admin/notifications/debug/validate-firebase', name: 'admin_notification_validate_firebase', methods: ['POST'])]
    public function validateFirebase(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('notification_validate_firebase', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_notification_debug']));
        }

        $result = $this->firebaseConnectionValidator->validate();
        $this->addFlash($result->isSuccess() ? 'success' : 'danger', $result->describe());

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_notification_debug']));
    }

    #[Route('/admin/notifications/debug/trigger/{type}/{club}', name: 'admin_notification_trigger', methods: ['POST'], requirements: ['type' => 'ROUND_DRAWN|NEW_REGISTRANT|MATCH_RESULT|ADMIN_MESSAGE'])]
    public function trigger(Request $request, string $type, Club $club): Response
    {
        if (!$this->isCsrfTokenValid('notification_trigger', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_notification_debug']));
        }

        $trigger = self::DEBUG_TRIGGERS[$type];
        $this->pushNotificationService->notifyUsers(
            [(string) $club->getUser()->getId()],
            $trigger['title'],
            sprintf('Debug trigger for %s', $club->getName()),
            $trigger['data'],
        );

        $this->addFlash('success', sprintf('Dispatched a %s test push to %s. Force-process the queue (or wait for the cron) to actually send it.', $type, $club->getName()));

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_notification_debug']));
    }
}
