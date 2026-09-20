<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Repository\ClubRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\UserDeviceRepository;
use App\Service\Notification\FirebaseConnectionValidator;
use App\Service\Notification\PushNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manual debug tools for the push-notification pipeline — kept separate from the already-large
 * DashboardController per the "developer tools" precedent it already establishes there
 * (cleanup-entities, generate-leaderboards). Every action here mirrors that controller's own
 * conventions for CSRF-protected POST + flash + redirect back to the debug page — with one
 * deliberate exception: `forceProcessQueue()` shells out to a genuinely separate PHP process
 * (`Symfony\Component\Process\Process`) instead of running in-process via
 * `Symfony\Bundle\FrameworkBundle\Console\Application`, the way `cleanupEntities()`/
 * `generateLeaderboards()` do. `messenger:consume` is a long-running *worker* command, not a
 * quick one-shot script — Messenger's `Worker` calls `services_resetter->reset()` after every
 * message it processes (to stop state leaking between messages in a real long-running worker),
 * and that reset includes `security.token_storage`'s `setToken` reset method. Run in-process,
 * sharing this web request's own container, that reset wipes the *current admin's own
 * authenticated token* mid-request — logging them out the moment this action's response is
 * written. A separate process has its own container, so it can't touch this one's security state.
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
        private readonly UserDeviceRepository $userDeviceRepository,
    ) {}

    #[Route('/admin/notifications/debug', name: 'admin_notification_debug', methods: ['GET'])]
    public function debug(): Response
    {
        return $this->render('admin/notifications/debug.html.twig', [
            'clubs'          => $this->clubRepository->findBy([], ['name' => 'ASC']),
            'recentLogs'     => $this->notificationLogRepository->findBy([], ['createdAt' => 'DESC'], 20),
            'triggerTypes'   => array_keys(self::DEBUG_TRIGGERS),
            // Surfaces the exact question "has ANY device ever actually registered for push"
            // directly, instead of only being inferable after the fact from a Notification Log
            // entry saying "0 eligible recipients found" — a real incident where an admin
            // broadcast's poll-delivered in-app overlay arrived fine but no OS push notification
            // did, because zero UserDevice rows existed at all.
            'recentDevices'  => $this->userDeviceRepository->findBy([], ['lastActiveAt' => 'DESC'], 10),
            'deviceCount'    => $this->userDeviceRepository->count([]),
        ]);
    }

    #[Route('/admin/notifications/debug/force-process-queue', name: 'admin_notification_force_process_queue', methods: ['POST'])]
    public function forceProcessQueue(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('notification_force_process_queue', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_notification_debug']));
        }

        $php = (new PhpExecutableFinder())->find();
        if ($php === false) {
            $this->addFlash('danger', 'Could not locate the PHP binary to run messenger:consume.');

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_notification_debug']));
        }

        $process = new Process(
            [$php, 'bin/console', 'messenger:consume', 'async', '--time-limit=20', '--limit=200', '--no-interaction'],
            $kernel->getProjectDir(),
        );
        // A little over the command's own --time-limit, so a hung process is still killed
        // rather than blocking this request indefinitely.
        $process->setTimeout(30);
        $process->run();

        $this->addFlash($process->isSuccessful() ? 'success' : 'danger', 'Queue processed.');
        $this->addFlash('info', nl2br(htmlspecialchars(trim($process->getOutput() . $process->getErrorOutput()))));

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
