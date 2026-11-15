<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Location\CountryProfile;
use App\Enum\Location\CountryProfileStatus;
use Doctrine\DBAL\Connection;
use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Render smoke test for the starter-pack check screen. It asserts the page comes back,
 * not what the numbers are: the point of the screen is to report whatever the pool
 * currently holds, and the test database holds nothing, so there is no meaningful count
 * to assert. What IS worth guarding is that the empty-pool path renders at all — the
 * funnel table, the verdict banner and the findings list all have to survive a pool with
 * zero rows, which is the state every fresh environment starts in.
 */
class StarterPackCheckControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'starter-pack-check-test-admin@example.com';

    /**
     * The admin firewall's Doctrine EntityUserProvider re-fetches by email on every
     * request, so an in-memory-only Admin reads as unauthenticated — persist first.
     */
    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    /** @var array<string, string|null> code => status before this test touched it; null = row did not exist */
    private array $countrySnapshot = [];

    /**
     * Make exactly `$codes` live, and nothing else.
     *
     * STATUS IS FLIPPED, NOT DELETED AND RECREATED. `location.code` carries a foreign key onto
     * `country_profile`, and the test database has locations for real countries — so deleting
     * EN fails with a constraint violation rather than giving a clean slate. Flipping status
     * leaves every referencing row untouched.
     *
     * Every other profile is demoted for the duration, because the assertion that matters is
     * "only these appear", and a live country seeded by another test would make that pass or
     * fail for reasons this file cannot see.
     */
    private function setLiveCodes(string ...$codes): void
    {
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(CountryProfile::class);

        foreach ($repo->findAll() as $profile) {
            $this->countrySnapshot[$profile->getCode()] ??= $profile->getStatus()->value;
            $profile->setStatus(CountryProfileStatus::DRAFT);
        }

        foreach ($codes as $code) {
            $profile = $repo->findOneBy(['code' => $code]);

            if ($profile === null) {
                // Tracked as null so teardown deletes it rather than demoting a row that was
                // never there — and a row this test created has no locations pointing at it.
                $this->countrySnapshot[$code] = null;
                $profile = new CountryProfile($code, $code);
                $em->persist($profile);
            }

            $profile->setStatus(CountryProfileStatus::LIVE);
        }

        $em->flush();
        $em->clear();
    }

    /**
     * Put every status back.
     *
     * A LIVE profile left behind shows up in `liveCountryCodes()` for every later test — the
     * leak `BootstrapCountryEnableTest` documents.
     */
    private function restoreCountries(): void
    {
        if ($this->countrySnapshot === []) {
            return;
        }

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(CountryProfile::class);

        foreach ($this->countrySnapshot as $code => $status) {
            $profile = $repo->findOneBy(['code' => $code]);

            if ($profile === null) {
                continue;
            }

            if ($status === null) {
                $em->remove($profile);
                continue;
            }

            $profile->setStatus(CountryProfileStatus::from($status));
        }

        $em->flush();
        $this->countrySnapshot = [];
    }

    protected function tearDown(): void
    {
        $this->restoreCountries();

        parent::tearDown();
    }

    public function testScreenRendersItsCountryPickerBeforeAnyCountryIsChosen(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN', 'US');

        // Reached through EasyAdmin's entry point, not the bare route: AdminRouterSubscriber
        // only populates the `ea` Twig context for the dashboard's own /admin route.
        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_starter_pack_check']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select[name="country"]'));
        self::assertCount(1, $crawler->filter('select[name="tier"]'));
    }

    public function testCheckingACountryRendersTheFunnelAndAVerdict(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN', 'US');

        $crawler = $client->request('GET', '/admin', [
            'routeName' => 'admin_starter_pack_check',
            'country'   => 'US',
            'tier'      => 8,
        ]);

        self::assertResponseIsSuccessful();

        // One funnel row per position, each ending in a readable result badge, plus a
        // verdict banner at the top.
        self::assertCount(4, $crawler->filter('#spc-funnel tbody tr'));
        // Position + Needs + four funnel steps + Can draw + status: the funnel header is
        // built from the first row's own steps, so a miscounted header means the steps
        // never reached the template.
        self::assertCount(8, $crawler->filter('#spc-funnel thead th'));
        // .bmc-badge and #spc-verdict, not Bootstrap's .badge and .alert — the rebuild moved
        // both. The counts and the wording are what this test is about, not the class names,
        // but a selector has to name something so these name the current ones.
        self::assertCount(4, $crawler->filter('#spc-funnel tbody tr .bmc-badge'));
        self::assertStringContainsString('starter pack', $crawler->filter('#spc-verdict')->text());
    }

    public function testTheScreenIsNotPubliclyReadable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin', ['routeName' => 'admin_starter_pack_check']);

        self::assertResponseStatusCodeSame(302, 'the admin firewall should redirect an anonymous request to the login form');
    }

    /**
     * The warm button is the one control on this screen that writes, so it only appears once a
     * check has been run — there is nothing to warm before a country is chosen, and binding it
     * to the rendered result keeps it off a dropdown the admin changed but never submitted.
     */
    public function testWarmButtonIsAbsentUntilACountryHasBeenChecked(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN', 'US');

        // Matched on the form it submits rather than on its label: the button's copy is
        // upper-cased by the redesign and would be re-worded again by the next one, where what
        // the test actually cares about — that the warm control is absent until a check has
        // run — is a property of the route it posts to.
        $warm = 'form[action*="admin_starter_pack_warm"] button[type="submit"]';

        $crawler = $client->request('GET', '/admin?routeName=admin_starter_pack_check');
        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter($warm));

        $crawler = $client->request('GET', '/admin?routeName=admin_starter_pack_check&country=EN&tier=8');
        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter($warm));
    }

    public function testWarmRejectsAPostWithoutAValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN', 'US');

        $before = $this->playerCount();

        $client->request('POST', '/admin/starter-pack-check/warm', [
            '_token'  => 'wrong',
            'country' => 'EN',
            'tier'    => 8,
        ]);

        self::assertResponseRedirects();
        $this->assertSame($before, $this->playerCount(), 'A bad token must generate nothing.');
    }

    /** An unknown code must not reach app:pool:warm, which would warm nothing and report success. */
    public function testWarmRejectsACodeThatIsNotACountry(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN', 'US');

        $crawler = $client->request('GET', '/admin?routeName=admin_starter_pack_check&country=EN&tier=8');
        $token   = $crawler->filter('input[name="_token"]')->attr('value');

        $before = $this->playerCount();

        $client->request('POST', '/admin/starter-pack-check/warm', [
            '_token'  => $token,
            'country' => 'ZZ',
            'tier'    => 8,
        ]);

        self::assertResponseRedirects();
        $this->assertSame($before, $this->playerCount());
    }

    private function playerCount(): int
    {
        return (int) self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(p.id) FROM App\\Entity\\Player p')
            ->getSingleScalarResult();
    }

    public function testThePageRendersInTheRedesignedShell(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN', 'US');

        $client->request('GET', '/admin', ['routeName' => 'admin_starter_pack_check', 'country' => 'US', 'tier' => 8]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bmc-title', 'STARTER PACK CHECK');
        self::assertSelectorTextContains('.bmc-crumb', 'CONFIG');
        self::assertSelectorExists('.bmc-page');
        self::assertSelectorNotExists('.card-body', 'no Bootstrap cards should survive the rebuild');
        self::assertSelectorNotExists('.form-select', 'selects must use the .bmc-select component');
        self::assertSelectorNotExists('.alert', 'the verdict is a card band now, not a Bootstrap alert');
    }

    /**
     * THE CHECK IS GET AND THE WARM IS POST, and they must stay two forms.
     *
     * The check's result has to be a linkable URL; the warm creates data and must be CSRF
     * guarded. One form cannot be both. The warm also posts the server's own last-checked
     * country rather than the select's current value, so it cannot act on a dropdown that was
     * changed but never submitted — asserted by reading its hidden input rather than the select.
     */
    public function testTheCheckAndTheWarmAreSeparateFormsWithTheRightMethods(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN', 'US');

        $crawler = $client->request('GET', '/admin?routeName=admin_starter_pack_check&country=EN&tier=8');
        self::assertResponseIsSuccessful();

        $check = $crawler->filter('form[method="get"]')->first();
        self::assertSame('admin_starter_pack_check', $check->filter('input[name="routeName"]')->attr('value'));

        $warm = $crawler->filter('form[action*="admin_starter_pack_warm"]');
        self::assertCount(1, $warm);
        self::assertSame('post', strtolower((string) $warm->attr('method')));
        self::assertCount(1, $warm->filter('input[name="_token"]'));
        self::assertSame('EN', $warm->filter('input[name="country"]')->attr('value'));
        self::assertSame('8', $warm->filter('input[name="tier"]')->attr('value'));
        self::assertCount(
            1,
            $warm->filter('button[data-bmc-dialog-title]'),
            'generating a starter pack writes data, so it must confirm first',
        );
    }

    // ── The live-only picker, added 2026-11-15 ──────────────────────────

    public function testThePickerListsOnlyLiveCountries(): void
    {
        // THE WHOLE POINT. Generation-capability is compiled into the enum and lists 25 codes;
        // LIVE is an operator decision and is usually far fewer. This page is about serving
        // real players, so offering a country nobody can pick is offering a dead end.
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN');

        $options = $client->request('GET', '/admin', ['routeName' => 'admin_starter_pack_check'])
            ->filter('select[name="country"] option')
            ->each(static fn ($node) => $node->attr('value'));

        // The placeholder plus England, and nothing else — ES and DE are generation-capable
        // but not live, so they must not appear.
        self::assertSame(['', 'EN'], $options);
    }

    public function testACountryTakenLiveAppearsWithoutACodeChange(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN', 'US');

        $options = $client->request('GET', '/admin', ['routeName' => 'admin_starter_pack_check'])
            ->filter('select[name="country"] option')
            ->each(static fn ($node) => $node->attr('value'));

        self::assertContains('US', $options);
        self::assertContains('EN', $options);
    }

    public function testWithNoLiveCountriesThePickerIsDisabledAndSaysWhatToDo(): void
    {
        // A real state on a fresh box, and the one an empty dropdown would leave someone
        // puzzling over. The selects stay rendered — disabled, not removed — so the page keeps
        // its shape.
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes();

        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_starter_pack_check']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select[name="country"]'), 'the select stays, it is only disabled');
        self::assertCount(1, $crawler->filter('select[name="country"][disabled]'));
        self::assertCount(0, $crawler->filter('select[name="country"] option[value!=""]'), 'no country options');
        self::assertStringContainsString('NO LIVE COUNTRIES', $crawler->filter('.bmc-empty-note')->text());
    }

    public function testANonLiveCountryIsStillCheckableByUrl(): void
    {
        // DELIBERATE ASYMMETRY: the dropdown is a menu, not a validator. A pre-flight check you
        // cannot run until after going live is not a pre-flight check, and the diagnostic
        // already reports the missing profile as a finding of its own.
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setLiveCodes('EN');

        $client->request('GET', '/admin', [
            'routeName' => 'admin_starter_pack_check',
            'country'   => 'ES',
            'tier'      => 8,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#spc-funnel');
    }

    /**
     * GENERATE must warm the TIER, not just the country.
     *
     * The flat warm generates from PoolConfig's single distribution, which aims at no tier,
     * while the check fails on `currentAbility BETWEEN tier_min AND tier_max` — so without
     * --tier this button adds players that can never satisfy the band it was pressed to fix,
     * and an operator can press it indefinitely with the number never moving. That is the
     * bug this work removed, and it would come back silently: the button would still succeed,
     * still flash green, and still change nothing.
     *
     * A source assertion rather than a behavioural one, deliberately. Exercising the real
     * POST would generate hundreds of players in the test database for an assertion about
     * one argument, and the failure mode here is a dropped argument, which reading the
     * argument list catches exactly.
     */
    public function testTheWarmButtonWarmsTheCheckedTierAndNotOnlyTheCountry(): void
    {
        $source = file_get_contents(
            \dirname(__DIR__, 3) . '/src/Controller/Admin/StarterPackCheckController.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString("'app:pool:warm'", $source);
        self::assertStringContainsString("'--tier'", $source, 'the warm must be tier-targeted');
    }
}
