<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\CountryEnrichment;

use App\Entity\Location\CountryProfile;
use App\Enum\Location\EnrichmentRunStatus;
use App\Enum\Location\EnrichmentTrigger;
use App\Repository\Location\CountryProfileRepository;
use App\Service\Location\CountryEnrichment\CountryEnrichmentUnavailableException;
use App\Service\Location\CountryEnrichment\CountryProfileEnrichmentService;
use App\Service\Location\CountryEnrichment\CountryProfileFacts;
use App\Service\Location\CountryEnrichment\CountryProfileValidator;
use App\Tests\Support\FakeCountryProfileEnricher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ask, validate, write, record — against a real database and a scripted provider.
 *
 * The provider is faked for the reason every test here fakes it: a real call would be slow,
 * non-deterministic and billed.
 */
class CountryProfileEnrichmentServiceTest extends KernelTestCase
{
    private const CODE = 'ZC';

    private EntityManagerInterface $em;

    private Connection $connection;

    private FakeCountryProfileEnricher $enricher;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->enricher   = new FakeCountryProfileEnricher();

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $this->connection->executeStatement('DELETE FROM location_enrichment_run WHERE country_code = :c', ['c' => self::CODE]);
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::CODE]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::CODE]);
        $this->em->clear();
    }

    public function testItWritesTheAcceptedFieldsAndRecordsARun(): void
    {
        $country = $this->seedCountry();
        $this->enricher->always($this->goodFacts());

        $verdict = $this->service()->enrich($country);

        self::assertTrue($verdict->hasAnything());
        $this->em->clear();

        $stored = $this->reload();
        self::assertSame(['minLat' => 49.9, 'maxLat' => 60.9, 'minLng' => -8.6, 'maxLng' => 1.8], $stored->getBbox());
        self::assertSame(5_000_000, $stored->getLandPenceMin());
        self::assertSame(900_000_000, $stored->getLandPenceMax());
        self::assertSame('GBR', $stored->getIsoAlpha3());
        self::assertSame('Europe/London', $stored->getDefaultTimezone());

        $run = $this->connection->fetchAssociative(
            'SELECT trigger_type, provider, model, queued, succeeded, failed, status
               FROM location_enrichment_run WHERE country_code = :c',
            ['c' => self::CODE],
        );

        self::assertSame(EnrichmentTrigger::ADMIN_COUNTRY_PROFILE->value, $run['trigger_type']);
        self::assertSame('fake', $run['provider']);
        self::assertSame(EnrichmentRunStatus::COMPLETE->value, $run['status']);
        // Written complete in one go: there is no queue to drain and no later tick to close it.
        self::assertSame(0, (int) $run['failed']);
        self::assertGreaterThan(0, (int) $run['succeeded']);
    }

    /**
     * The guarantee that makes a re-run safe.
     *
     * country_profile has no per-field lock columns, so "a hand correction is never undone"
     * has to mean "a field that already holds a value is left alone".
     */
    public function testARerunDoesNotOverwriteWhatIsAlreadyThere(): void
    {
        $country = $this->seedCountry();
        $country->setIsoAlpha3('XXX');
        $this->em->flush();

        $this->enricher->always($this->goodFacts());
        $verdict = $this->service()->enrich($country);

        self::assertContains('isoAlpha3', $verdict->skipped);
        $this->em->clear();
        self::assertSame('XXX', $this->reload()->getIsoAlpha3());
    }

    public function testOverwriteReplacesIt(): void
    {
        $country = $this->seedCountry();
        $country->setIsoAlpha3('XXX');
        $this->em->flush();

        $this->enricher->always($this->goodFacts());
        $this->service()->enrich($country, overwrite: true);

        $this->em->clear();
        self::assertSame('GBR', $this->reload()->getIsoAlpha3());
    }

    public function testARefusedFieldLeavesTheStoredValueUntouched(): void
    {
        $country = $this->seedCountry();

        // A box with its edges the wrong way round, and a good alpha-3 alongside it.
        $this->enricher->always(new CountryProfileFacts(
            bbox: ['minLat' => 60.9, 'maxLat' => 49.9, 'minLng' => -8.6, 'maxLng' => 1.8],
            isoAlpha3: 'GBR',
        ));

        $verdict = $this->service()->enrich($country);

        self::assertArrayHasKey('bbox', $verdict->rejected);
        $this->em->clear();

        $stored = $this->reload();
        self::assertNull($stored->getBbox(), 'a refused field must not be written, nor nulled');
        self::assertSame('GBR', $stored->getIsoAlpha3(), 'the rest of the same answer still lands');

        $failed = (int) $this->connection->fetchOne(
            'SELECT failed FROM location_enrichment_run WHERE country_code = :c',
            ['c' => self::CODE],
        );
        self::assertSame(1, $failed);
    }

    public function testADryRunWritesNothingAtAll(): void
    {
        $country = $this->seedCountry();
        $this->enricher->always($this->goodFacts());

        $verdict = $this->service()->enrich($country, dryRun: true);

        self::assertTrue($verdict->hasAnything(), 'it still reports what it would have written');
        $this->em->clear();

        self::assertNull($this->reload()->getBbox());
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM location_enrichment_run WHERE country_code = :c', ['c' => self::CODE]),
            'a dry run must not leave a run row either',
        );
    }

    public function testAnUnreachableProviderThrowsRatherThanReportingAnEmptyAnswer(): void
    {
        $country = $this->seedCountry();
        $this->enricher->setConfigured(false);

        $this->expectException(CountryEnrichmentUnavailableException::class);

        $this->service()->enrich($country);
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    private function service(): CountryProfileEnrichmentService
    {
        return new CountryProfileEnrichmentService(
            $this->enricher,
            new CountryProfileValidator($this->connection),
            $this->em,
            new \Psr\Log\NullLogger(),
        );
    }

    private function seedCountry(): CountryProfile
    {
        $profile = new CountryProfile(self::CODE, 'Countrylandia');
        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    private function reload(): CountryProfile
    {
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
}
