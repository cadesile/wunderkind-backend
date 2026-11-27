<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Enum\MessageDisplayType;
use App\Service\Notification\OwnerNoticeService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Notify form on the admin user page: email the owner, send a message to their device,
 * optionally push it. Two modes, chosen by the button pressed:
 *
 * - "test": the email goes ONLY to the signed-in admin's own address, subject prefixed
 *   [TEST], and nothing is sent to the device. This is how to proof a notice against the
 *   dev database, which holds real player accounts.
 * - "send": the real thing, to the owner.
 *
 * Only ever redirects, never renders, so it can target the plain route (same exemption as
 * admin_user_kill_session; see src/Controller/Admin/CLAUDE.md).
 */
#[IsGranted('ROLE_ADMIN')]
class UserNotifyController extends AbstractController
{
    public function __construct(
        private readonly OwnerNoticeService $notices,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/admin/users/{id}/notify', name: 'admin_user_notify', methods: ['POST'])]
    public function notify(Request $request, User $user): Response
    {
        $back = $this->redirect(
            $this->adminUrlGenerator
                ->setController(UserCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($user->getId())
                ->generateUrl() . '#notify',
        );

        if (!$this->isCsrfTokenValid('notify_user_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token. Nothing was sent.');

            return $back;
        }

        $subject     = trim((string) $request->request->get('subject'));
        $body        = trim((string) $request->request->get('body'));
        $pushBody    = trim((string) $request->request->get('push_body'));
        $sendEmail   = $request->request->getBoolean('send_email');
        $sendDevice  = $request->request->getBoolean('send_device');
        $sendPush    = $sendDevice && $request->request->getBoolean('send_push');
        $isTest      = $request->request->get('mode') === 'test';
        $displayType = MessageDisplayType::tryFrom((string) $request->request->get('display_type'))
            ?? MessageDisplayType::MODAL_BLOCKING;

        if ($subject === '' || $body === '') {
            $this->addFlash('danger', 'Subject and message are both required. Nothing was sent.');

            return $back;
        }

        if ($sendPush && $pushBody === '') {
            $this->addFlash('danger', 'Push is on but the push text is empty. Nothing was sent.');

            return $back;
        }

        $unfilled = $this->notices->unfilledPlaceholders($subject, $body, $sendPush ? $pushBody : '');
        if ($unfilled !== [] && !$isTest) {
            $this->addFlash('danger', sprintf(
                'Fill in %s before sending. Nothing was sent.',
                implode(', ', $unfilled),
            ));

            return $back;
        }

        $admin = $this->getUser();
        $admin = $admin instanceof Admin ? $admin : null;

        if ($isTest) {
            if ($admin === null) {
                $this->addFlash('danger', 'Could not work out your admin email for the test. Nothing was sent.');

                return $back;
            }

            $to = $this->notices->sendEmail($user, '[TEST] ' . $subject, $body, $admin->getEmail());
            $this->addFlash('success', sprintf(
                'Test email sent to %s (your address, not the player\'s). Nothing was sent to the player\'s device.%s',
                $to,
                $unfilled !== [] ? ' Still to fill in: ' . implode(', ', $unfilled) . '.' : '',
            ));

            return $back;
        }

        if (!$sendEmail && !$sendDevice) {
            $this->addFlash('warning', 'No channel was ticked, so nothing was sent.');

            return $back;
        }

        $done = [];

        if ($sendEmail) {
            $done[] = 'email to ' . $this->notices->sendEmail($user, $subject, $body);
        }

        if ($sendDevice) {
            $message = $this->notices->sendDeviceMessage($user, $subject, $body, $displayType, $sendPush, $pushBody, $admin);
            $devices = $this->notices->countPushDevices($user);
            $done[]  = 'device message (' . str_replace('_', ' ', $displayType->value) . ')';

            if ($sendPush) {
                $done[] = $devices > 0
                    ? sprintf('push to %d device%s', $devices, $devices === 1 ? '' : 's')
                    : 'push queued, but this account has no registered device, so none will arrive';
            }

            $this->logger->info('Admin notice sent', [
                'userId'         => (string) $user->getId(),
                'adminMessageId' => (string) $message->getId(),
                'email'          => $sendEmail,
                'push'           => $sendPush,
                'admin'          => $admin?->getEmail(),
            ]);
        }

        $this->addFlash('success', 'Sent to ' . $user->getEmail() . ': ' . implode('; ', $done) . '.');

        return $back;
    }
}
