<?php

namespace App\Service;

use App\Entity\EmailVerification;
use App\Entity\User;
use App\Enum\VerificationPurpose;
use App\Repository\EmailVerificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailVerificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly EmailVerificationRepository $verificationRepo,
        private readonly string $mailerFrom,
        private readonly string $mailerFromName,
        private readonly string $projectDir,
    ) {}

    public function sendVerificationEmail(User $user): void
    {
        $this->softExpireActive($user, VerificationPurpose::REGISTRATION);

        $code         = $this->generateCode();
        $verification = new EmailVerification($user, $code, VerificationPurpose::REGISTRATION);
        $this->em->persist($verification);
        $this->em->flush();

        $this->mailer->send(
            $this->baseEmail($user)
                ->subject('Your Build My Club verification code')
                ->html($this->renderHtml(
                    'Verify Your Account',
                    $this->verificationBlock($code),
                    "This code expires in <strong style='color:#E8CF59'>15 minutes</strong>.<br><br>" .
                    "<span style='color:#9bb0c4;font-size:8px'>If you didn't register for Build My Club, you can safely ignore this email.</span>"
                ))
                ->text(
                    "Your verification code is: {$code}\n\n" .
                    "This code expires in 15 minutes.\n\n" .
                    "If you didn't register for Build My Club, you can safely ignore this email."
                )
                ->embedFromPath($this->logoPath(), 'logo', 'image/png')
        );
    }

    public function sendPasswordResetEmail(User $user): void
    {
        $this->softExpireActive($user, VerificationPurpose::PASSWORD_RESET);

        $code         = $this->generateCode();
        $verification = new EmailVerification($user, $code, VerificationPurpose::PASSWORD_RESET);
        $this->em->persist($verification);
        $this->em->flush();

        $this->mailer->send(
            $this->baseEmail($user)
                ->subject('Reset your Build My Club password')
                ->html($this->renderHtml(
                    'Password Reset',
                    $this->verificationBlock($code),
                    "This code expires in <strong style='color:#E8CF59'>15 minutes</strong>.<br><br>" .
                    "<span style='color:#9bb0c4;font-size:8px'>If you didn't request a password reset, you can safely ignore this email.</span>"
                ))
                ->text(
                    "Your password reset code is: {$code}\n\n" .
                    "This code expires in 15 minutes.\n\n" .
                    "If you didn't request a password reset, you can safely ignore this email."
                )
                ->embedFromPath($this->logoPath(), 'logo', 'image/png')
        );
    }

    public function sendPasswordResetConfirmationEmail(User $user): void
    {
        $this->mailer->send(
            $this->baseEmail($user)
                ->subject('Your Build My Club password has been changed')
                ->html($this->renderHtml(
                    'Password Updated',
                    "<div style='text-align:center;padding:20px 0'>
                        <div style='display:inline-block;background:#0f1420;border:1px solid #E8CF59;padding:16px 24px'>
                            <span style='color:#E8CF59;font-size:10px;letter-spacing:2px'>&#10003; SUCCESS</span>
                        </div>
                    </div>",
                    "Your password has been successfully updated.<br><br>" .
                    "<span style='color:#C44747;font-size:8px'>If you didn't make this change, please contact support immediately.</span>"
                ))
                ->text(
                    "Your password has been successfully updated.\n\n" .
                    "If you didn't make this change, please contact support immediately."
                )
                ->embedFromPath($this->logoPath(), 'logo', 'image/png')
        );
    }

    /**
     * Validate a submitted registration code.
     *
     * @return 'ok'|'invalid'|'expired'|'max_attempts'
     */
    public function verifyCode(User $user, string $code): string
    {
        return $this->verifyForPurpose($user, $code, VerificationPurpose::REGISTRATION, function (EmailVerification $v, User $u): void {
            $u->setIsVerified(true);
            $u->setVerifiedAt(new \DateTimeImmutable());
        });
    }

    /**
     * Validate a submitted password-reset code.
     *
     * @return 'ok'|'invalid'|'expired'|'max_attempts'
     */
    public function verifyPasswordResetCode(User $user, string $code): string
    {
        return $this->verifyForPurpose($user, $code, VerificationPurpose::PASSWORD_RESET);
    }

    // -------------------------------------------------------------------------

    /**
     * @param callable(EmailVerification, User): void $onSuccess  Side-effects on success.
     * @return 'ok'|'invalid'|'expired'|'max_attempts'
     */
    private function verifyForPurpose(User $user, string $code, VerificationPurpose $purpose, ?callable $onSuccess = null): string
    {
        $verification = $this->verificationRepo->findActiveForUser($user, $purpose);

        if ($verification === null) {
            $any = $this->verificationRepo->findLatestUnverifiedForUser($user, $purpose);

            if ($any !== null && $any->isLockedOut()) {
                return 'max_attempts';
            }
            if ($any !== null && $any->isExpired()) {
                return 'expired';
            }

            return 'invalid';
        }

        if ($verification->isLockedOut()) {
            return 'max_attempts';
        }

        if ($verification->isExpired()) {
            return 'expired';
        }

        if ($verification->getCode() !== $code) {
            $verification->incrementAttempts();
            $this->em->flush();
            return 'invalid';
        }

        $verification->markVerified();
        if ($onSuccess !== null) {
            $onSuccess($verification, $user);
        }
        $this->em->flush();

        return 'ok';
    }

    private function softExpireActive(User $user, VerificationPurpose $purpose): void
    {
        $existing = $this->verificationRepo->findActiveForUser($user, $purpose);
        if ($existing !== null) {
            $existing->setExpiresAt(new \DateTimeImmutable());
            $this->em->persist($existing);
        }
    }

    private function baseEmail(User $user): Email
    {
        return (new Email())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($user->getEmail());
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function logoPath(): string
    {
        return $this->projectDir . '/public/images/logo.png';
    }

    private function verificationBlock(string $code): string
    {
        return "
            <div style='text-align:center;padding:20px 0'>
                <p style='color:#9bb0c4;font-size:8px;letter-spacing:2px;margin:0 0 12px;text-transform:uppercase'>Your Code</p>
                <div style='display:inline-block;background:#0f1420;border:2px solid #E8CF59;padding:16px 32px;box-shadow:0 0 20px rgba(232,207,89,0.15)'>
                    <span style='color:#E8CF59;font-size:32px;letter-spacing:16px;font-weight:bold;font-family:\"Courier New\",monospace'>{$code}</span>
                </div>
            </div>";
    }

    private function renderHtml(string $heading, string $body, string $footer): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Build My Club</title>
</head>
<body style="margin:0;padding:0;background-color:#0f1420;font-family:'Courier New',Courier,monospace">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0f1420;padding:40px 20px">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:500px;background-color:#1e2448;border:1px solid #2a3060">

          <!-- Header -->
          <tr>
            <td style="padding:32px 24px;text-align:center;border-bottom:2px solid #E8CF59">
              <img src="cid:logo" alt="Build My Club" width="80" height="80" style="display:block;margin:0 auto 12px">
              <p style="color:#E8CF59;font-size:11px;letter-spacing:3px;margin:0 0 4px;text-transform:uppercase;font-weight:bold">BUILD MY CLUB</p>
              <p style="color:#9bb0c4;font-size:7px;letter-spacing:2px;margin:0;text-transform:uppercase">Football Management Simulation</p>
            </td>
          </tr>

          <!-- Heading -->
          <tr>
            <td style="padding:24px 24px 0;text-align:center">
              <p style="color:#e8f0f4;font-size:10px;letter-spacing:2px;margin:0;text-transform:uppercase;border-left:3px solid #E8CF59;padding-left:10px;text-align:left">{$heading}</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:16px 24px">
              {$body}
            </td>
          </tr>

          <!-- Footer text -->
          <tr>
            <td style="padding:0 24px 24px">
              <p style="color:#e8f0f4;font-size:9px;line-height:2;margin:0">{$footer}</p>
            </td>
          </tr>

          <!-- Bottom bar -->
          <tr>
            <td style="padding:16px 24px;border-top:1px solid #2a3060;text-align:center">
              <p style="color:#9bb0c4;font-size:7px;letter-spacing:1px;margin:0;text-transform:uppercase">&copy; 2026 Build My Club. All rights reserved.</p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
