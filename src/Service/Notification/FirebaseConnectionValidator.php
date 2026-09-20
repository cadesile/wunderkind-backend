<?php

declare(strict_types=1);

namespace App\Service\Notification;

use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\SenderIdMismatch;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * "Is Firebase actually configured correctly right now" — a one-click check for the admin debug
 * page, independent of sending a real push and waiting to see if it arrives on a device. Two
 * tiers, run in sequence, so the report can say exactly which one failed:
 *
 * 1. Structural (no network call): can `FirebaseMessagingFactory::create()` even build a
 *    `Messaging` client from the currently configured `FIREBASE_SERVICE_ACCOUNT_JSON`? This
 *    alone catches the bug that motivated this class — an empty/malformed
 *    `FIREBASE_SERVICE_ACCOUNT_JSON` (e.g. a GitHub secret that was never set, which GitHub
 *    Actions silently substitutes with an empty string rather than failing the workflow).
 * 2. Live connectivity: a `validateOnly: true` send to a throwaway token — exercises the real
 *    authenticated request to Google. The dummy token is never a real device, so Google is
 *    always going to reject *it* — the point isn't whether the send itself would have
 *    succeeded, it's *why* it was rejected. `AuthenticationError`/`ApiConnectionFailed`/
 *    `ServerError`/`ServerUnavailable` mean the credentials themselves are the problem — the
 *    request never got past Google's own auth check. `InvalidArgument`/`InvalidMessage`/
 *    `NotFound`/`SenderIdMismatch` mean Google *authenticated the request fine* and rejected
 *    only the (deliberately fake) token/message — i.e. the credentials work.
 */
class FirebaseConnectionValidator
{
    /** Not a real device — `validateOnly: true` never actually delivers to it either way. */
    private const DUMMY_TOKEN = 'FirebaseConnectionValidator-dummy-token-0000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly string $serviceAccountJson,
    ) {}

    public function validate(): FirebaseValidationResult
    {
        try {
            $messaging = FirebaseMessagingFactory::create($this->serviceAccountJson);
        } catch (\Throwable $e) {
            return new FirebaseValidationResult(
                credentialsParsed: false,
                acceptedByGoogle: false,
                errorMessage: $e->getMessage(),
                errorClass: $e::class,
            );
        }

        try {
            $message = CloudMessage::new()
                ->withNotification(Notification::create('Validation', 'ping'))
                ->withToken(self::DUMMY_TOKEN);
            $messaging->send($message, validateOnly: true);
        } catch (InvalidArgument|InvalidMessage|NotFound|SenderIdMismatch) {
            // Google authenticated the request and rejected only the deliberately-fake
            // token/message — the credentials themselves are good.
            return new FirebaseValidationResult(credentialsParsed: true, acceptedByGoogle: true);
        } catch (AuthenticationError $e) {
            return new FirebaseValidationResult(
                credentialsParsed: true,
                acceptedByGoogle: false,
                errorMessage: $e->getMessage(),
                errorClass: $e::class,
            );
        } catch (\Throwable $e) {
            // Anything else (ApiConnectionFailed/ServerError/ServerUnavailable/a generic
            // connectivity failure) — can't confirm the credentials work, report it as such.
            return new FirebaseValidationResult(
                credentialsParsed: true,
                acceptedByGoogle: false,
                errorMessage: $e->getMessage(),
                errorClass: $e::class,
            );
        }

        return new FirebaseValidationResult(credentialsParsed: true, acceptedByGoogle: true);
    }
}
