<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The shared foundation for the redesigned non-list pages is actually wired up.
 *
 * Cheap, but it covers the two ways this silently does nothing: an asset that is not registered
 * (the page renders, just unstyled and inert) and one registered without the cache-buster (the
 * browser keeps serving the previous file, so a fix looks like it never landed).
 */
class BmcPagesAssetsSmokeTest extends WebTestCase
{
    public function testBothNewAssetsAreServedWithACacheBuster(): void
    {
        $client = static::createClient();

        $em    = $client->getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => 'bmc-pages-smoke@example.com']);

        if ($admin === null) {
            $admin = new Admin('bmc-pages-smoke@example.com');
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();

        self::assertMatchesRegularExpression('~bmc-pages\.css\?v=\w+~', $html, 'css registered and versioned');
        self::assertMatchesRegularExpression('~bmc-pages\.js\?v=\w+~', $html, 'js registered and versioned');

        // Deferred for the same reason the rest of the shell is: EasyAdmin emits these in
        // <head>, and bmc-pages.js binds against the DOM the moment it runs.
        self::assertMatchesRegularExpression('~<script[^>]+bmc-pages\.js[^>]+defer~', $html);
    }

    public function testTheFilesExistAndCarryWhatThePagesNeed(): void
    {
        $css = file_get_contents(\dirname(__DIR__, 3) . '/public/bmc-pages.css');
        $js  = file_get_contents(\dirname(__DIR__, 3) . '/public/bmc-pages.js');

        self::assertStringContainsString('.bmc-page', $css);
        // The host fix: without it these pages inherit the stock gutter on top of their own.
        self::assertStringContainsString('.bmc-main > .content-body:has(.bmc-page)', $css);
        // Renamed on the way in — bmc-admin.css owns .bmc-body for the list pages.
        self::assertStringNotContainsString("\n.bmc-body ", $css);

        self::assertStringContainsString('BMC.runJobs', $js);
        self::assertStringContainsString('BMC.confirm', $js);
    }

    /**
     * The int validator accepts a leading minus; the money one still does not.
     *
     * Six game-config settings are negative by design — bondMin at -100, the bond and coach
     * trust thresholds at -20 — and a digits-only check marked each of them "Whole number" on
     * its own saved value, so the save bar opened pinned to invalid.
     */
    public function testTheIntegerValidatorAcceptsNegativeValues(): void
    {
        $js = file_get_contents(\dirname(__DIR__, 3) . '/public/bmc-pages.js');

        self::assertStringContainsString("kind === 'money' ? /^\\d+$/ : /^-?\\d+$/", $js);
        self::assertStringContainsString('function intOf(el) { var v = norm(el); return /^-?\\d+$/', $js);

        $twig = file_get_contents(\dirname(__DIR__, 3) . '/templates/admin/game_config.html.twig');

        foreach (['bondMin', 'bondDecayFloor', 'coachManagerTrustThreshold'] as $name) {
            self::assertMatchesRegularExpression(
                \sprintf('~name="%s"[^>]*data-min="-\d~', $name),
                $twig,
                "{$name} declares a negative minimum, so the validator must allow a minus",
            );
        }
    }

    public function testTheBaseTemplateAndDialogArePresent(): void
    {
        $base = \dirname(__DIR__, 3) . '/templates/admin/';

        self::assertFileExists($base . 'bmc_page.html.twig');
        self::assertFileExists($base . '_bmc_dialog.html.twig');

        $page = file_get_contents($base . 'bmc_page.html.twig');

        // The two blocks the shell actually exposes. Overriding `content` instead would
        // swallow the page strip — see the layout's own note at :126-139.
        self::assertStringContainsString('{% block content_header_wrapper %}', $page);
        self::assertStringContainsString('{% block main %}', $page);

        // Anchored to the start of a line: the docblock discusses `{% block content %}`, and
        // a bare substring check matches the prose as readily as a real declaration.
        self::assertDoesNotMatchRegularExpression('~^\s*\{%\s*block content\s*%\}~m', $page);
    }
}
