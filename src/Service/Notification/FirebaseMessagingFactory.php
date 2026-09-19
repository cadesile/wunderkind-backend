<?php

namespace App\Service\Notification;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;

/**
 * Builds the Kreait `Messaging` service directly from the raw service-account JSON in
 * `FIREBASE_SERVICE_ACCOUNT_JSON`, bypassing kreait/firebase-bundle's own `projects.*.credentials`
 * config binding. That YAML path round-trips the JSON through Symfony's config/env layer,
 * which has been unreliable for a multi-line JSON string with embedded `\n`s in a PEM private
 * key (the same class of trap as `JWT_SECRET_KEY` — see docs/deploy/hetzner.md). Passing the
 * raw string straight into `json_decode()` here avoids that layer entirely.
 */
class FirebaseMessagingFactory
{
    public static function create(string $serviceAccountJson): Messaging
    {
        $credentials = json_decode($serviceAccountJson, true, flags: JSON_THROW_ON_ERROR);

        return (new Factory())
            ->withServiceAccount($credentials)
            ->createMessaging();
    }
}
