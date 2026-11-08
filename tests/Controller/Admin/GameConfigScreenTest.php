<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Config → Game config: that every field the save handler reads is still on the page.
 *
 * THIS IS THE PAGE WHERE A DROPPED FIELD MATTERS MOST. It is 155 named fields posting to one
 * handler, and those constants are synced to every client on the next /api/sync — so a field
 * that stops rendering does not fail, it quietly freezes an engine constant at whatever it
 * last held, for everyone, until somebody notices the gameplay is wrong.
 *
 * WRITTEN BECAUSE THE REBUILD WAS A MARKUP TRANSFORM, NOT A RETYPE. 2259 lines of Bootstrap
 * became bmc markup through pattern replacement precisely so no field could be lost in
 * transcription; a first attempt with a looser pattern swallowed eight inputs and seven
 * textareas whole, and a census is what caught it. This is that census, kept.
 *
 * DERIVED FROM THE HANDLER AT RUNTIME, not from a list copied here. A hardcoded list would
 * agree with itself and miss the next field somebody adds to the handler without adding to the
 * form — which is the actual failure this is guarding.
 */
class GameConfigScreenTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'game-config-screen@example.com';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $this->client->loginUser($admin, 'admin');
    }

    private function body(): string
    {
        $this->client->request('GET', '/admin', ['routeName' => 'admin_game_config']);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * Every `$request->request->get('x')` in saveGameConfig() must have a matching control.
     *
     * @return list<string>
     */
    private function fieldsTheHandlerReads(): array
    {
        $source = file_get_contents(\dirname(__DIR__, 3) . '/src/Controller/Admin/DashboardController.php');
        $start  = strpos($source, 'function saveGameConfig');
        self::assertNotFalse($start, 'saveGameConfig() has moved — this test needs updating');

        // To the next method at the same indentation.
        $end  = strpos($source, "\n    }\n", $start);
        $body = substr($source, $start, $end - $start);

        preg_match_all("/request->(?:request|get)->(?:get|all)\('([^']+)'/", $body, $m);

        return array_values(array_unique(array_diff($m[1], ['_token'])));
    }

    public function testEveryFieldTheSaveHandlerReadsIsStillRendered(): void
    {
        $body   = $this->body();
        $fields = $this->fieldsTheHandlerReads();

        self::assertGreaterThan(100, \count($fields), 'the handler scrape found suspiciously few fields');

        $missing = [];

        foreach ($fields as $name) {
            // Scalar fields render as name="x"; array-shaped ones as name="x[0][min]".
            $scalar = \sprintf('name="%s"', $name);
            $array  = \sprintf('name="%s[', $name);

            if (!str_contains($body, $scalar) && !str_contains($body, $array)) {
                $missing[] = $name;
            }
        }

        self::assertSame(
            [],
            $missing,
            'these fields are read on save but no longer offered, so they would silently freeze: '
            . implode(', ', $missing),
        );
    }

    /** All 32 sections post together, so there is one form and one page-level save bar. */
    public function testItIsASingleFormWithOnePageLevelSaveBar(): void
    {
        $this->body();

        self::assertSelectorExists('form[data-bmc-form]#game-config-form');
        self::assertSelectorExists('[data-bmc-savebar] [data-bmc-save]');
        self::assertSelectorNotExists('.bmc-savebar--inline');
    }

    public function testThePageRendersInTheRedesignedShell(): void
    {
        $this->body();

        self::assertSelectorTextContains('.bmc-title', 'GAME CONFIG');
        self::assertSelectorTextContains('.bmc-crumb', 'CONFIG');
        self::assertSelectorExists('.bmc-page');
    }

    /** The transform is only finished if no Bootstrap reaches the browser. */
    public function testNoBootstrapMarkupSurvivedTheTransform(): void
    {
        $body = $this->body();
        $page = substr($body, (int) strpos($body, '<div class="bmc-page'));
        $page = substr($page, 0, (int) strpos($page, 'id="bmc-dialog"'));

        foreach (['card-body', 'form-control', 'form-text', 'col-md-', 'row g-', 'btn btn-',
                  'badge bg-', 'fa fa-', 'text-muted', '--bs-', 'input-group', 'accordion'] as $needle) {
            self::assertStringNotContainsString($needle, $page, "Bootstrap leftover: {$needle}");
        }
    }

    /** The section tabs are what make 32 sections usable; losing them hides most of the page. */
    public function testTheSectionNavigationSurvives(): void
    {
        $this->body();

        self::assertSelectorExists('#cfg-tab-nav');
        self::assertSelectorExists('.cfg-group-btn');
        self::assertSelectorExists('.cfg-tab');
        self::assertSelectorExists('.cfg-section');
    }
}
