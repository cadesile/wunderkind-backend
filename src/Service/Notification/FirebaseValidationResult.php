<?php

declare(strict_types=1);

namespace App\Service\Notification;

/** Result of `FirebaseConnectionValidator::validate()`. */
final class FirebaseValidationResult
{
    public function __construct(
        public readonly bool $credentialsParsed,
        public readonly bool $acceptedByGoogle,
        public readonly ?string $errorMessage = null,
        public readonly ?string $errorClass = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->credentialsParsed && $this->acceptedByGoogle;
    }

    /** Human-readable summary for a flash message. */
    public function describe(): string
    {
        if ($this->isSuccess()) {
            return 'Firebase credentials parsed and accepted by Google.';
        }

        if (!$this->credentialsParsed) {
            return sprintf('FIREBASE_SERVICE_ACCOUNT_JSON is missing or malformed: %s (%s)', $this->errorMessage, $this->errorClass);
        }

        return sprintf('Credentials parsed but were rejected by Google: %s (%s)', $this->errorMessage, $this->errorClass);
    }
}
