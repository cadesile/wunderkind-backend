<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Asserting that a flash message reached the page, without asserting its casing.
 *
 * WHY THIS EXISTS. The admin shell renders flashes as toasts, and the toast upper-cases its
 * message — `{{ message|upper }}` in layout.html.twig. Every test that pinned a flash with
 * assertSelectorTextContains('body', 'Invalid CSRF token.') therefore went red the day the
 * shell landed, across four unrelated files, on a change that altered nothing they were
 * testing. PoolConfigControllerTest grew a private helper for it; this is that helper, shared,
 * so the fourth file does not grow a fourth copy.
 *
 * IT DELIBERATELY COMPARES CASE-INSENSITIVELY rather than upper-casing the expectation. The
 * controllers still addFlash() in sentence case, so a test that expects UPPER would be pinning
 * the presentation rather than the message — and would break again the first time a flash is
 * rendered somewhere that does not upper-case it.
 */
trait AssertsFlashTrait
{
    private function assertFlash(KernelBrowser $client, string $message): void
    {
        self::assertStringContainsStringIgnoringCase(
            $message,
            strip_tags((string) $client->getResponse()->getContent()),
            \sprintf('expected the flash "%s" on the page', $message),
        );
    }
}
