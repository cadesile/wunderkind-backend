<?php

namespace App\Controller\Api;

use App\Repository\GameConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class BetaRequestController extends AbstractController
{
    private const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly MailerInterface $mailer,
    ) {}

    #[Route('/beta-request', name: 'api_beta_request', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $config = $this->gameConfigRepository->getConfig();

        $email          = trim((string) $request->request->get('email', ''));
        $captchaToken   = (string) $request->request->get('g-recaptcha-response', '');
        $secretKey      = $config->getRecaptchaSecretKey();
        $recipientEmail = $config->getBetaRequestEmail();

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email address.'], Response::HTTP_BAD_REQUEST);
        }

        // Verify reCAPTCHA only when a secret key is configured
        if ($secretKey) {
            if (empty($captchaToken)) {
                return $this->json(['error' => 'CAPTCHA verification required.'], Response::HTTP_BAD_REQUEST);
            }

            $verified = $this->verifyCaptcha($secretKey, $captchaToken, $request->getClientIp());
            if (!$verified) {
                return $this->json(['error' => 'CAPTCHA verification failed. Please try again.'], Response::HTTP_BAD_REQUEST);
            }
        }

        if (!$recipientEmail) {
            // Silently succeed — no destination configured yet
            return $this->json(['success' => true]);
        }

        $message = (new Email())
            ->from('admin@buildmyclub.co.uk')
            ->to($recipientEmail)
            ->subject('Beta Access Request — ' . $email)
            ->text(implode("\n", [
                'New beta access request received.',
                '',
                'Email: ' . $email,
                'Submitted: ' . (new \DateTimeImmutable())->format('Y-m-d H:i:s T'),
                'IP: ' . ($request->getClientIp() ?? 'unknown'),
            ]));

        $this->mailer->send($message);

        return $this->json(['success' => true]);
    }

    private function verifyCaptcha(string $secret, string $token, ?string $ip): bool
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => $ip ?? '',
                ]),
                'timeout' => 5,
            ],
        ]);

        $body = @file_get_contents(self::RECAPTCHA_VERIFY_URL, false, $context);
        if ($body === false) {
            return false;
        }

        $data = json_decode($body, true);
        return isset($data['success']) && $data['success'] === true;
    }
}
