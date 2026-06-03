<?php

namespace App\Service;

use App\Entity\EmailVerification;
use App\Entity\User;
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

    /**
     * Soft-expire any active verifications for this user, generate a new 6-digit
     * code, persist it, and dispatch the verification email.
     */
    public function sendVerificationEmail(User $user): void
    {
        // Soft-expire existing active verifications
        $existing = $this->verificationRepo->findActiveForUser($user);
        if ($existing !== null) {
            $existing->setExpiresAt(new \DateTimeImmutable());
            $this->em->persist($existing);
        }

        $code = $this->generateCode();
        $verification = new EmailVerification($user, $code);
        $this->em->persist($verification);
        $this->em->flush();

        $email = (new Email())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($user->getEmail())
            ->subject('Your Wunderkind Factory verification code')
            ->text(
                "Your verification code is: {$code}\n\n" .
                "This code expires in 15 minutes.\n\n" .
                "If you didn't register for Wunderkind Factory, " .
                "you can safely ignore this email."
            );

        $this->mailer->send($email);
    }

    /**
     * Validate a submitted code against the user's active verification.
     *
     * @return 'ok'|'invalid'|'expired'|'max_attempts'
     */
    public function verifyCode(User $user, string $code): string
    {
        $verification = $this->verificationRepo->findActiveForUser($user);

        if ($verification === null) {
            // Check for expired/locked record
            $any = $this->em->createQueryBuilder()
                ->select('ev')
                ->from(EmailVerification::class, 'ev')
                ->where('ev.user = :user')
                ->andWhere('ev.verifiedAt IS NULL')
                ->setParameter('user', $user)
                ->orderBy('ev.createdAt', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

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

        // Success
        $verification->markVerified();
        $user->setIsVerified(true);
        $user->setVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();

        return 'ok';
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
