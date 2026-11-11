<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Repository\Location\CountryProfileRepository;
use App\Service\Location\CountryEnrichment\CountryProfileEnricherInterface;
use App\Service\Location\CountryEnrichment\CountryProfileFacts;
use App\Tests\Support\FakeCountryProfileEnricher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The PROGRESS BY COUNTRY column that fills in a country's own data.
 *
 * WHY IT IS TESTED THROUGH HTTP. The button spends money, so the thing most worth pinning is
 * that it cannot be made to spend any without a valid token — and that it is a POST, because
 * the whole reason the single-location trigger stopped being a GET is that a crawler or a
 * browser prefetch must not be able to start a billed call.
 *
 * The enricher is replaced with a scripted fake: no test in this suite may make a real call.
 */
class CountryProfileEnrichAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'country-enrich-admin@example.com';
    private const CODE        = 'ZE';

    private KernelBrowser $client;

    private FakeCountryProfileEnricher $enricher;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // disableReboot() so the service swapped into the container below survives the
        // request — a reboot would hand the real, unconfigured enricher back.
        $this->client->disableReboot();

        $this->enricher = new FakeCountryProfileEnricher();
        self::getContainer()->set(CountryProfileEnricherInterface::class, $this->enricher);

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
        $c->executeStatement('DELETE FROM location_enrichment_run WHERE country_code = :c', ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::CODE]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    public function testItWritesTheCountrysOwnFields(): void
    {
        $this->seedCountry();
        $this->enricher->always($this->goodFacts());

        $this->post($this->token('country_profile_enrich'));

        self::assertResponseRedirects();
        self::assertSame(1, $this->enricher->calls());

        $profile = $this->reload();
        self::assertNotNull($profile->getBbox());
        self::assertSame('GBR', $profile->getIsoAlpha3());
    }

    public function testABadTokenSpendsNothing(): void
    {
        $this->seedCountry();
        $this->enricher->always($this->goodFacts());

        $this->post('not-the-token');

        self::assertResponseRedirects();
        self::assertSame(0, $this->enricher->calls(), 'a bad token must not reach the provider at all');
        self::assertNull($this->reload()->getBbox());
    }

    /**
     * A GET must never be able to spend money.
     *
     * This is the rule the single-location trigger was rewritten for: an EasyAdmin row action
     * is a plain GET, and a CSRF token in a query string cannot be validated, so the link goes
     * to the screen and the screen carries the POST.
     */
    public function testItIsPostOnly(): void
    {
        $this->seedCountry();

        $this->client->request('GET', '/admin/locations/enrichment/country', ['country' => self::CODE]);

        self::assertResponseStatusCodeSame(405);
        self::assertSame(0, $this->enricher->calls());
    }

    public function testAnUnknownCountryAsksNothing(): void
    {
        $this->seedCountry();

        $this->client->request('POST', '/admin/locations/enrichment/country', [
            '_token'  => $this->token('country_profile_enrich'),
            'country' => 'QQ',
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, $this->enricher->calls());
    }

    public function testTheProgressColumnShowsHowMuchOfTheCountryIsFilledIn(): void
    {
        $this->seedCountry();

        $this->client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('PROFILE', $html);
        // A freshly seeded country has none of the eight, and the count doubles as the button.
        self::assertStringContainsString('0/8', $html);
    }

    /**
     * The fill-gaps promise, end to end.
     *
     * country_profile has no per-field locks, so this is the only thing standing between a
     * second click and an operator's hand-typed bounding box.
     */
    public function testASecondClickLeavesHandSetValuesAlone(): void
    {
        $country = $this->seedCountry();
        $country->setIsoAlpha3('XXX');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->enricher->always($this->goodFacts());
        $this->post($this->token('country_profile_enrich'));

        self::assertSame('XXX', $this->reload()->getIsoAlpha3());
    }

    public function testOverwriteReplacesThem(): void
    {
        $country = $this->seedCountry();
        $country->setIsoAlpha3('XXX');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->enricher->always($this->goodFacts());
        $this->post($this->token('country_profile_enrich'), overwrite: true);

        self::assertSame('GBR', $this->reload()->getIsoAlpha3());
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    private function post(string $token, bool $overwrite = false): void
    {
        $this->client->request('POST', '/admin/locations/enrichment/country', [
            '_token'    => $token,
            'country'   => self::CODE,
            'overwrite' => $overwrite ? '1' : '0',
        ]);
    }

    private function seedCountry(): CountryProfile
    {
        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $profile = new CountryProfile(self::CODE, 'Enrichia');
        $em->persist($profile);
        $em->flush();

        return $profile;
    }

    private function reload(): CountryProfile
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(CountryProfileRepository::class)->findByCode(self::CODE);
    }

    private function goodFacts(): CountryProfileFacts
    {
        return new CountryProfileFacts(
            bbox: ['minLat' => 49.9, 'maxLat' => 60.9, 'minLng' => -8.6, 'maxLng' => 1.8],
            landPenceMin: 5_000_000,
            landPenceMax: 900_000_000,
            isoAlpha3: 'GBR',
            continent: 'Europe',
            defaultTimezone: 'Europe/London',
            currencyCode: 'GBP',
            landPriceCurrencyCode: 'GBP',
        );
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

    /**
     * The REVIEW and FAILED cells render a badge, not its source.
     *
     * They built the markup by concatenation and finished with |raw, which binds to the last
     * string LITERAL rather than to the concatenation — so the filter applied to '</span>'
     * alone and autoescaping printed the opening tag as text. An operator saw
     * `<span class="bmc-badge bmc-badge--danger">1</span>` sitting in the cell.
     */
    public function testTheReviewAndFailedCellsRenderMarkupRatherThanItsSource(): void
    {
        $this->seedCountryWithFailedTown();

        $html = $this->screenHtml();

        self::assertStringNotContainsString('&lt;span class=&quot;bmc-badge', $html);
        self::assertStringContainsString('<span class="bmc-badge bmc-badge--danger">1</span>', $html);
    }

    /**
     * The Pull towns picker offers every country, not the 25 the registry happens to hold.
     *
     * Limiting it to the curated list excluded exactly the countries the feature exists for:
     * pulling town names is how a country the registry never had gets any.
     */
    public function testThePullTownsPickerOffersEveryCountry(): void
    {
        $html = $this->screenHtml();

        // A country with curated places, and one without — the Faroe Islands have no registry
        // entry at all and were previously unreachable from this picker.
        self::assertStringContainsString('value="EN"', $html);
        self::assertStringContainsString('value="FO"', $html);
        self::assertStringContainsString('id="suggestFilter"', $html, 'and it is filterable');
    }

    private function seedCountryWithFailedTown(): void
    {
        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $profile = $this->seedCountry();

        $location = new \App\Entity\Location\Location($profile, 'Failville', self::CODE . '-f1', 'failville');
        $location->setRegion('R');
        $em->persist($location);
        $em->flush();

        // settle() writes the status with raw SQL, so the fixture does too.
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE location SET enrichment_status = 'failed' WHERE id = :id",
            ['id' => (string) $location->getId()],
        );
        $em->clear();
    }

    private function screenHtml(): string
    {
        $this->client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}
