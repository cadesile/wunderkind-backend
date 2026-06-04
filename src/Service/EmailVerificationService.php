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
                ->subject('Your Wunderkind Factory verification code')
                ->text(
                    "Your verification code is: {$code}\n\n" .
                    "This code expires in 15 minutes.\n\n" .
                    "If you didn't register for Wunderkind Factory, " .
                    "you can safely ignore this email."
                )
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
                ->subject('Reset your Wunderkind Factory password')
                ->text(
                    "Your password reset code is: {$code}\n\n" .
                    "This code expires in 15 minutes.\n\n" .
                    "If you didn't request a password reset, " .
                    "you can safely ignore this email."
                )
        );
    }

    public function sendPasswordResetConfirmationEmail(User $user): void
    {
        $this->mailer->send(
            $this->baseEmail($user)
                ->subject('Your Wunderkind Factory password has been changed')
                ->text(
                    "Your password has been successfully updated.\n\n" .
                    "If you didn't make this change, please contact support immediately."
                )
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
}
