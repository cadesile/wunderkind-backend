<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Service\Notification\FirebaseConnectionValidator;
use PHPUnit\Framework\TestCase;

/**
 * Only the tier-1 (structural) path is unit-testable here — tier 2 needs a real authenticated
 * request to Google, which this sandbox can't make. Tier 2's exception-class triage was verified
 * manually against the real dev Firebase project instead (see the plan's Verification section).
 */
class FirebaseConnectionValidatorTest extends TestCase
{
    public function testEmptyServiceAccountJsonFailsTier1(): void
    {
        // The exact failure mode this class exists to catch: GitHub Actions silently substitutes
        // an unset secret with an empty string rather than failing the workflow.
        $result = (new FirebaseConnectionValidator(''))->validate();

        self::assertFalse($result->credentialsParsed);
        self::assertFalse($result->acceptedByGoogle);
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('FIREBASE_SERVICE_ACCOUNT_JSON is missing or malformed', $result->describe());
    }

    public function testMalformedJsonFailsTier1(): void
    {
        $result = (new FirebaseConnectionValidator('{not valid json'))->validate();

        self::assertFalse($result->credentialsParsed);
        self::assertFalse($result->isSuccess());
    }

    public function testWellFormedButIncompleteServiceAccountJsonFailsTier1(): void
    {
        // Valid JSON, but missing the fields kreait's Factory::withServiceAccount() requires
        // (private_key, client_email, etc.) — still a tier-1 (construction-time) failure, not a
        // network call.
        $result = (new FirebaseConnectionValidator(json_encode(['type' => 'service_account'])))->validate();

        self::assertFalse($result->credentialsParsed);
        self::assertFalse($result->isSuccess());
    }
}
