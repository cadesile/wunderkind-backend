<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;

/**
 * No inline admin script may decide whether to wire itself up by testing `window.BMC`.
 *
 * THIS IS A BUG THAT LEAVES NO TRACE. `bmc-pages.js` is registered with `->defer()`
 * (`DashboardController::configureAssets`), so it executes AFTER every inline `<script>` in
 * the body. A top-level `if (!window.BMC) return;` therefore runs while `BMC` is still
 * undefined, returns, and never attaches its listeners — the button is enabled, looks
 * correct, and does nothing when clicked. No console error, no failed request, nothing to
 * search for.
 *
 * It has now happened twice: the Location config page's reclassify RUN button, and the
 * locations dashboard's TOP UP WITH AI button, which is what turned it up.
 *
 * **The correct shape is to reference `BMC` only inside a handler**, which fires long after
 * the deferred script has loaded — what the three pool pages do. Testing `window.BMC` AT USE
 * is fine and stays allowed (`if (window.BMC) BMC.toast(...)` in `starter_config`): by then
 * it is a real feature check, not a race with the loader.
 */
class DeferredScriptGuardTest extends TestCase
{
    public function testNoAdminTemplateGuardsItsSetupOnWindowBmc(): void
    {
        $offenders = [];

        foreach ($this->adminTemplates() as $path) {
            $contents = (string) file_get_contents($path);

            foreach (explode("\n", $contents) as $i => $line) {
                if ($this->isEarlyReturnGuardOnBmc($line)) {
                    $offenders[] = sprintf('%s:%d — %s', basename($path), $i + 1, trim($line));
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            'An inline script returns early if window.BMC is absent. Because bmc-pages.js is '
            . 'deferred, it IS absent at that point, so the code below the guard never runs and '
            . "its buttons silently do nothing. Reference BMC inside the handler instead.\n  %s",
            implode("\n  ", $offenders),
        ));
    }

    /** The regex has to be sharp in both directions, so pin both. */
    public function testTheGuardDetectorMatchesTheBugAndNotTheCorrectShape(): void
    {
        self::assertTrue($this->isEarlyReturnGuardOnBmc('    if (!window.BMC) return;'));
        self::assertTrue($this->isEarlyReturnGuardOnBmc('    if (!card || !window.BMC || !BMC.runJobs) { return; }'));

        // A positive check at the point of use is correct and must stay allowed.
        self::assertFalse($this->isEarlyReturnGuardOnBmc('    if (window.BMC) BMC.toast("SAVED");'));
        self::assertFalse($this->isEarlyReturnGuardOnBmc('    if (!card || !btn) { return; }'));
    }

    /**
     * An early return conditioned on `window.BMC` being present.
     *
     * Matches `if (!window.BMC) return;` and `if (!a || !window.BMC || !BMC.x) { return; }`.
     * Deliberately does NOT match `if (window.BMC) BMC.toast(...)`.
     */
    private function isEarlyReturnGuardOnBmc(string $line): bool
    {
        return preg_match('/if\s*\(.*!\s*window\.BMC.*\)\s*\{?\s*return/', $line) === 1;
    }

    /** @return list<string> */
    private function adminTemplates(): array
    {
        $dir   = \dirname(__DIR__, 3) . '/templates/admin';
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $files[] = $file->getPathname();
            }
        }

        self::assertNotEmpty($files, 'fixture check: the admin templates must be findable');

        return $files;
    }
}
