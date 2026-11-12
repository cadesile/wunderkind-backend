<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\NamePoolRegistry;
use App\Service\CountryContent\NamePoolValidator;
use App\Service\CountryContent\Suggestion\NamePoolSuggesterInterface;
use App\Tests\Support\FakeNamePoolSuggester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Authoring a country's player and staff name pools from the locations dashboard.
 *
 * WHAT THIS IS REALLY GUARDING. Thirty-nine of the seventy-one countries ship with no name
 * pool, so their generated players were silently named in English and their generated STAFF
 * crashed the warm. Giving a country a pool was a deploy. These pin that an operator can do
 * it from the screen, that the AI top-up extends rather than replaces, and that a GET never
 * spends money.
 *
 * The suggester is faked — no test here may make a real call.
 */
class LocationNamePoolAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'location-names-admin@example.com';

    /**
     * A REAL Country case with no shipped name pool — the exact state this feature is for.
     *
     * Not an invented code: the card renders from Country::nationality(), so a non-case has
     * nothing to show. The Faroe Islands are unauthored and unreferenced by other fixtures.
     */
    private const CODE = 'FO';

    private const NATIONALITY = 'Faroese';

    private KernelBrowser $client;

    private FakeNamePoolSuggester $suggester;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->suggester = new FakeNamePoolSuggester();
        self::getContainer()->set(NamePoolSuggesterInterface::class, $this->suggester);

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

    /** Fixture check first: this is a feature about a country that ships nameless. */
    public function testTheFixtureCountryShipsWithNoPool(): void
    {
        self::assertFalse(NamePoolRegistry::has(self::NATIONALITY));
    }

    /**
     * Read inside the card, not the page.
     *
     * YOURS / BUILT IN / MISSING are shared with the club-naming card directly above, which
     * for this fixture country also reads MISSING — so a page-wide assertion here passes or
     * fails on the wrong card's state.
     */
    public function testTheCardShowsTheCountsAndSaysWhereTheNamesCameFrom(): void
    {
        $this->seedCountry();

        $card = $this->nameCardHtml();

        self::assertStringContainsString('PLAYER &amp; STAFF NAMES', $card);
        self::assertStringContainsString('MISSING', $card, 'a poolless country must say so');
        self::assertStringNotContainsString('YOURS', $card);

        $this->save(['firstNames' => $this->lines(12, 'First'), 'lastNames' => $this->lines(12, 'Last')]);

        $card = $this->nameCardHtml();
        self::assertStringContainsString('YOURS', $card);
        self::assertStringNotContainsString('MISSING', $card);
        self::assertStringContainsString('of ' . NamePoolValidator::TARGET_PER_LIST, $card, 'and how far off target it is');
    }

    public function testSavingStoresBothListsAndTheyComeBackOnTheForm(): void
    {
        $this->seedCountry();

        $this->save([
            'firstNames' => $this->lines(12, 'Jákup'),
            'lastNames'  => $this->lines(12, 'Poulsen'),
        ]);

        $profile = $this->reload();
        self::assertCount(12, $profile->getFirstNames());
        self::assertSame('Jákup1', $profile->getFirstNames()[0], 'diacritics must survive the form');
        self::assertCount(12, $profile->getLastNames());

        self::assertStringContainsString('Jákup1', $this->dashboardHtml());
    }

    /** Lines, never commas: "Van der Berg" and "O'Neill" contain spaces and punctuation. */
    public function testNamesAreOnePerLineAndAreTrimmed(): void
    {
        $this->seedCountry();

        $this->save([
            'firstNames' => "  Jákup  \n\nHeini\r\nTórur\nPól\nRúni\nEyðun\nSuni\nMeinhard\nAtli\nBárður\nHanus\nSjúrður",
            'lastNames'  => $this->lines(12, 'Last'),
        ]);

        $stored = $this->reload()->getFirstNames();

        self::assertSame('Jákup', $stored[0], 'leading and trailing spaces are not part of a name');
        self::assertNotContains('', $stored, 'a blank line is not a name');
        self::assertCount(12, $stored);
    }

    public function testABadTokenWritesNothing(): void
    {
        $this->seedCountry();

        $this->client->request('POST', '/admin/locations/dashboard/names', [
            '_token'     => 'not-the-token',
            'country'    => self::CODE,
            'firstNames' => $this->lines(12, 'First'),
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->reload()->getFirstNames());
    }

    /** A list too thin to name five hundred players without absurd repetition is refused. */
    public function testATooShortListIsRefusedAndTheOtherStillSaves(): void
    {
        $this->seedCountry();

        $this->save([
            'firstNames' => $this->lines(12, 'First'),
            'lastNames'  => "Only\nTwo",
        ]);

        $profile = $this->reload();
        self::assertCount(12, $profile->getFirstNames(), 'the rest of the same form still saves');
        self::assertNull($profile->getLastNames());
    }

    /** An empty override reaches array_rand([]), which throws. It must be unstorable. */
    public function testAnEmptyFieldStoresNullRatherThanAnEmptyList(): void
    {
        $country = $this->seedCountry();
        $country->setFirstNames($this->names(12, 'First'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->save(['firstNames' => '', 'lastNames' => '']);

        $stored = $this->reload()->getFirstNames();
        self::assertTrue($stored === null || $stored !== [], 'never an empty list');
    }

    /**
     * Saving an unchanged form on an authored country must not pin it to a copy.
     *
     * The form is prefilled with the EFFECTIVE names, so on one of the thirty-two authored
     * countries a no-op Save would otherwise convert "follows the shipped pool" into "frozen
     * copy of it as it was that day", and a later PR adding names would never reach it.
     */
    public function testSavingTheShippedListBackStoresNoOverride(): void
    {
        // England ships 150/150 and its own profile row is outside this test's purge, so the
        // comparison is made on the real row — read, saved back unchanged, and expected to
        // stay NULL.
        $england = self::getContainer()->get(CountryProfileRepository::class)->findByCode('EN');

        if ($england === null) {
            self::markTestSkipped('EN has no profile row in this database.');
        }

        self::assertNull($england->getFirstNames(), 'precondition: EN is on the shipped pool');

        $this->client->request('POST', '/admin/locations/dashboard/names', [
            '_token'     => $this->token('locations_names_EN'),
            'country'    => 'EN',
            'firstNames' => implode("\n", NamePoolRegistry::firstNames('English')),
            'lastNames'  => implode("\n", NamePoolRegistry::lastNames('English')),
        ]);

        self::assertResponseRedirects();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = self::getContainer()->get(CountryProfileRepository::class)->findByCode('EN');

        self::assertNull($reloaded->getFirstNames(), 'an unchanged save must not pin a copy');
        self::assertNull($reloaded->getLastNames());
    }

    public function testRevertPutsTheCountryBackOnTheShippedPools(): void
    {
        $country = $this->seedCountry();
        $country->setFirstNames($this->names(12, 'First'));
        $country->setLastNames($this->names(12, 'Last'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('POST', '/admin/locations/dashboard/names/clear', [
            '_token'  => $this->token('locations_clear_names_' . self::CODE),
            'country' => self::CODE,
        ]);

        self::assertResponseRedirects();

        $profile = $this->reload();
        self::assertNull($profile->getFirstNames());
        self::assertNull($profile->getLastNames());
    }

    public function testTopUpWritesWhatTheValidatorAcceptsAndReportsTheCount(): void
    {
        $this->seedCountry();
        $this->suggester->answerWith('firstNames', 150, 'Given');

        $this->topUp('firstNames');

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertTrue($body['success']);
        self::assertSame(150, $body['count']);
        self::assertStringContainsString('first names', $body['message']);
        self::assertCount(150, $this->reload()->getFirstNames());
    }

    /** One list per request: a top-up of first names must not touch the surnames. */
    public function testTopUpTouchesOnlyTheListItWasAskedFor(): void
    {
        $this->seedCountry();
        $this->suggester->answerWith('firstNames', 150, 'Given')->answerWith('lastNames', 150, 'Family');

        $this->topUp('firstNames');

        $profile = $this->reload();
        self::assertCount(150, $profile->getFirstNames());
        self::assertNull($profile->getLastNames());
        self::assertSame(1, $this->suggester->calls());
        self::assertSame('firstNames', $this->suggester->lastAsk()['list']);
    }

    /** The target is the plan's 150, and the ask is the shortfall against it. */
    public function testTopUpAsksForTheShortfallAgainstTheTarget(): void
    {
        $country = $this->seedCountry();
        $country->setFirstNames($this->names(20, 'Held'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->suggester->answerWith('firstNames', 130, 'Given');

        $this->topUp('firstNames');

        $ask = $this->suggester->lastAsk();
        self::assertSame(NamePoolValidator::TARGET_PER_LIST - 20, $ask['want']);
        self::assertCount(20, $ask['exclude'], 'what we hold goes as exclusions, or we pay for duplicates');

        $stored = $this->reload()->getFirstNames();
        self::assertSame('Held1', $stored[0], 'the names already held must survive a top-up');
        self::assertCount(NamePoolValidator::TARGET_PER_LIST, $stored);
    }

    /** A country at target costs nothing — the second click must not be billed. */
    public function testASecondTopUpAsksForNothing(): void
    {
        $country = $this->seedCountry();
        $country->setFirstNames($this->names(NamePoolValidator::TARGET_PER_LIST, 'Held'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->topUp('firstNames');

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertTrue($body['success']);
        self::assertTrue($body['skipped']);
        self::assertSame(0, $this->suggester->calls(), 'nothing may be asked for, and nothing billed');
    }

    /**
     * A GET MUST NEVER SPEND MONEY.
     *
     * The rule the whole admin follows: a link a crawler, a prefetch or a back button can
     * follow cannot reach a paid provider.
     */
    public function testTopUpIsPostOnlyAndTokenGuarded(): void
    {
        $this->seedCountry();

        $this->client->request('GET', '/admin/locations/dashboard/names/top-up', [
            'country' => self::CODE,
            'list'    => 'firstNames',
        ]);
        self::assertResponseStatusCodeSame(405);

        $this->client->request('POST', '/admin/locations/dashboard/names/top-up', [
            '_token'  => 'not-the-token',
            'country' => self::CODE,
            'list'    => 'firstNames',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->suggester->calls(), 'a bad token must not reach the provider');
    }

    public function testAnUnknownListIsRefusedBeforeTheProviderIsReached(): void
    {
        $this->seedCountry();

        $this->client->request('POST', '/admin/locations/dashboard/names/top-up', [
            '_token'  => $this->token('locations_top_up_names_' . self::CODE),
            'country' => self::CODE,
            'list'    => 'middleNames',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->suggester->calls());
    }

    /** A provider fault must come back as a failed job, not a 500 page or a silent success. */
    public function testAProviderFailureIsReportedAsAFailedJob(): void
    {
        $this->seedCountry();
        $this->suggester->fail(new \App\Service\CountryContent\Suggestion\NamePoolUnavailableException('provider is down'));

        $this->topUp('firstNames');

        self::assertResponseStatusCodeSame(500);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertFalse($body['success']);
        self::assertStringContainsString('provider is down', $body['message']);
        self::assertNull($this->reload()->getFirstNames());
    }

    public function testTheTopUpButtonSaysWhatToSetWhenThereIsNoProvider(): void
    {
        $this->seedCountry();
        $this->suggester->setConfigured(false);

        $html = $this->dashboardHtml();

        self::assertStringContainsString('LOCATION_ENRICHER_PROVIDER', $html);
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    private function topUp(string $list): void
    {
        $this->client->request('POST', '/admin/locations/dashboard/names/top-up', [
            '_token'  => $this->token('locations_top_up_names_' . self::CODE),
            'country' => self::CODE,
            'list'    => $list,
        ]);
    }

    /** @param array<string, string> $fields */
    private function save(array $fields): void
    {
        $this->client->request('POST', '/admin/locations/dashboard/names', [
            '_token'  => $this->token('locations_names_' . self::CODE),
            'country' => self::CODE,
        ] + $fields);

        self::assertResponseRedirects();
    }

    /** Just the PLAYER & STAFF NAMES card, so a badge the other cards share cannot answer for it. */
    private function nameCardHtml(): string
    {
        $html  = $this->dashboardHtml();
        $start = strpos($html, 'id="name-pools"');

        self::assertNotFalse($start, 'the name pool card must be on the page');

        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
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

    /** @return list<string> */
    private function names(int $count, string $prefix): array
    {
        $out = [];

        for ($i = 1; $i <= $count; $i++) {
            $out[] = $prefix . $i;
        }

        return $out;
    }

    private function lines(int $count, string $prefix): string
    {
        return implode("\n", $this->names($count, $prefix));
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
