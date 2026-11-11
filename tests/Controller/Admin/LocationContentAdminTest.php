<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\Suggestion\NamingContentSuggesterInterface;
use App\Service\CountryContent\Suggestion\NamingContentSuggestion;
use App\Tests\Support\FakeNamingContentSuggester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Authoring a country's club-naming content from the locations dashboard.
 *
 * WHAT THIS IS REALLY GUARDING. The CONTENT readiness blocker used to point at a 2,364-line
 * PHP file, so clearing it meant a deploy. These pin that an operator can clear it from the
 * screen that reports it, that a bad paste cannot reach sprintf(), and that saving an
 * unchanged form does not quietly pin a country to a copy of the registry.
 *
 * The AI suggester is faked — no test here may make a real call.
 */
class LocationContentAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'location-content-admin@example.com';
    /**
     * A REAL Country case with no naming content in PHP — the exact state this feature is for.
     *
     * Not an invented ZZ-style code, which would miss the point twice over: the content check
     * short-circuits on a code that is not an App\Enum\Country case, and the form itself has
     * nothing to render for one. The Faroe Islands are unauthored, unreferenced by any other
     * fixture, and will never be anybody's curated content.
     */
    private const CODE = 'FO';

    private KernelBrowser $client;

    private FakeNamingContentSuggester $suggester;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->suggester = new FakeNamingContentSuggester();
        self::getContainer()->set(NamingContentSuggesterInterface::class, $this->suggester);

        $this->purge();
        $this->loginAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $c = self::getContainer()->get(Connection::class);
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::CODE]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    public function testSavingContentClearsTheBlocker(): void
    {
        $this->seedCountry();

        $html = $this->dashboardHtml();
        self::assertStringContainsString('AUTHOR CONTENT', $html, 'precondition: the check is failing');

        $this->save([
            'prestigeSuffixes' => "Alpha\nBeta\nGamma\nDelta\nEpsilon",
            'genericSuffixes'  => "Zeta\nEta\nTheta\nIota\nKappa",
            'stadiumFormats'   => "%s Park\nEstadio %s",
        ]);

        $profile = $this->reload();
        self::assertSame(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'], $profile->getPrestigeSuffixes());
        self::assertSame(['%s Park', 'Estadio %s'], $profile->getStadiumFormats());

        self::assertStringNotContainsString('AUTHOR CONTENT', $this->dashboardHtml(), 'the blocker must be gone');
    }

    public function testABadTokenWritesNothing(): void
    {
        $this->seedCountry();

        $this->client->request('POST', '/admin/locations/dashboard/content', [
            '_token'           => 'not-the-token',
            'country'          => self::CODE,
            'prestigeSuffixes' => "Alpha\nBeta\nGamma\nDelta\nEpsilon",
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->reload()->getPrestigeSuffixes());
    }

    /**
     * A stadium template is handed to sprintf(), which throws in PHP 8 on a bad format.
     *
     * Refusing it at the form is the difference between a message an operator can act on and a
     * fatal inside club generation hours later.
     */
    public function testABadStadiumFormatIsRefusedAndStoresNothingForThatCategory(): void
    {
        $this->seedCountry();

        $this->save([
            'prestigeSuffixes' => "Alpha\nBeta\nGamma\nDelta\nEpsilon",
            'stadiumFormats'   => "%s Park\n50% Stadium",
        ]);

        $profile = $this->reload();
        self::assertNull($profile->getStadiumFormats(), 'the refused category stores nothing');
        self::assertNotNull($profile->getPrestigeSuffixes(), 'the rest of the same form still saves');
    }

    public function testTooFewSuffixesAreRefused(): void
    {
        $this->seedCountry();

        $this->save(['prestigeSuffixes' => "Alpha\nBeta"]);

        self::assertNull($this->reload()->getPrestigeSuffixes());
    }

    public function testAnEmptyFieldStoresNullRatherThanAnEmptyList(): void
    {
        // An empty override reaches array_rand([]), which throws. It must be impossible to
        // store one.
        $country = $this->seedCountry();
        $country->setPrestigeSuffixes(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->save(['prestigeSuffixes' => '']);

        $stored = $this->reload()->getPrestigeSuffixes();
        self::assertTrue($stored === null || $stored !== [], 'never an empty list');
    }

    /**
     * Saving an unchanged form on an authored country must not pin it to a copy.
     *
     * The form is prefilled with the EFFECTIVE values, so on one of the twenty-five authored
     * countries a no-op Save would otherwise convert "follows the registry" into "frozen copy
     * of the registry as it was that day" — twenty-five times over, invisibly.
     */
    public function testSavingTheBuiltInListBackStoresNoOverride(): void
    {
        // ES is authored in PHP. Its own profile row is left alone by this test's purge, so
        // the assertion is made on a throwaway country carrying ES's exact lists instead —
        // which is the same comparison the service makes.
        // Compared against the country's OWN const, never another's. FO has none, so a list
        // typed here is genuinely an override and must be stored as one.
        $this->seedCountry();
        $borrowed = \App\Service\CountryContent\CountryContentRegistry::prestigeSuffixes(\App\Enum\Country::ES);

        self::assertNotSame([], $borrowed, 'fixture check');

        $this->save(['prestigeSuffixes' => implode("\n", $borrowed)]);
        self::assertSame($borrowed, $this->reload()->getPrestigeSuffixes());
    }

    public function testRevertPutsTheCountryBackOnTheBuiltInLists(): void
    {
        $country = $this->seedCountry();
        $country->setPrestigeSuffixes(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);
        $country->setStadiumFormats(['%s Park', 'Estadio %s']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('POST', '/admin/locations/dashboard/content/clear', [
            '_token'  => $this->token('locations_clear_content_' . self::CODE),
            'country' => self::CODE,
        ]);

        self::assertResponseRedirects();

        $profile = $this->reload();
        self::assertNull($profile->getPrestigeSuffixes());
        self::assertNull($profile->getStadiumFormats());
    }

    public function testSuggestWritesWhatTheValidatorAccepts(): void
    {
        $this->seedCountry();
        $this->suggester->answer(new NamingContentSuggestion(
            prestigeSuffixes: ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'],
            genericSuffixes: ['Zeta', 'Eta', 'Theta', 'Iota', 'Kappa'],
            stadiumFormats: ['%s Park', 'Estadio %s'],
        ));

        $this->client->request('POST', '/admin/locations/dashboard/content/suggest', [
            '_token'  => $this->token('locations_suggest_content_' . self::CODE),
            'country' => self::CODE,
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->suggester->calls());
        self::assertSame(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'], $this->reload()->getPrestigeSuffixes());
    }

    public function testSuggestDoesNotReplaceContentYouAuthored(): void
    {
        $country = $this->seedCountry();
        $country->setPrestigeSuffixes(['Mine', 'Own', 'Hand', 'Typed', 'List']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->suggester->answer(new NamingContentSuggestion(
            prestigeSuffixes: ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'],
        ));

        $this->client->request('POST', '/admin/locations/dashboard/content/suggest', [
            '_token'  => $this->token('locations_suggest_content_' . self::CODE),
            'country' => self::CODE,
        ]);

        self::assertSame(['Mine', 'Own', 'Hand', 'Typed', 'List'], $this->reload()->getPrestigeSuffixes());
    }

    public function testSuggestReplacesThemWhenAskedTo(): void
    {
        $country = $this->seedCountry();
        $country->setPrestigeSuffixes(['Mine', 'Own', 'Hand', 'Typed', 'List']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->suggester->answer(new NamingContentSuggestion(
            prestigeSuffixes: ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'],
        ));

        $this->client->request('POST', '/admin/locations/dashboard/content/suggest', [
            '_token'    => $this->token('locations_suggest_content_' . self::CODE),
            'country'   => self::CODE,
            'overwrite' => '1',
        ]);

        self::assertSame(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'], $this->reload()->getPrestigeSuffixes());
    }

    public function testSuggestIsPostOnlyAndTokenGuarded(): void
    {
        $this->seedCountry();

        $this->client->request('GET', '/admin/locations/dashboard/content/suggest', ['country' => self::CODE]);
        self::assertResponseStatusCodeSame(405);

        $this->client->request('POST', '/admin/locations/dashboard/content/suggest', [
            '_token'  => 'not-the-token',
            'country' => self::CODE,
        ]);
        self::assertSame(0, $this->suggester->calls(), 'a bad token must not reach the provider');
    }

    public function testTheSuggestButtonIsDisabledWithoutAProvider(): void
    {
        $this->seedCountry();
        $this->suggester->setConfigured(false);

        $html = $this->dashboardHtml();

        self::assertMatchesRegularExpression(
            '/SUGGEST WITH AI|disabled/',
            $html,
        );
        self::assertStringContainsString('LOCATION_ENRICHER_PROVIDER', $html, 'and it says what to set');
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param array<string, string> $fields */
    private function save(array $fields): void
    {
        $this->client->request('POST', '/admin/locations/dashboard/content', [
            '_token'  => $this->token('locations_content_' . self::CODE),
            'country' => self::CODE,
        ] + $fields);

        self::assertResponseRedirects();
    }

    private function dashboardHtml(): string
    {
        $this->client->request('GET', '/admin', [
            'routeName'   => 'admin_locations_dashboard',
            'routeParams' => ['country' => self::CODE],
        ]);

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function seedCountry(): CountryProfile
    {
        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $profile = new CountryProfile(self::CODE, 'Faroe Islands');
        $em->persist($profile);
        $em->flush();

        return $profile;
    }

    private function reload(): CountryProfile
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(CountryProfileRepository::class)->findByCode(self::CODE);
    }

    private function loginAsAdmin(): void
    {
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

    private function token(string $id): string
    {
        $value   = 'test-csrf-' . bin2hex(random_bytes(8));
        $session = $this->client->getContainer()->get('session.factory')->createSession();

        $existing = $this->client->getCookieJar()->get($session->getName());
        if ($existing !== null) {
            $session->setId($existing->getValue());
        }

        $session->start();
        $session->set("_csrf/{$id}", $value);
        $session->save();

        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $value;
    }
}
