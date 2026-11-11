<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Enum\Country;
use App\Repository\LeagueRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\Location\LocationRepository;
use App\Repository\StarterConfigRepository;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\CountryContent\CountryNamingContentProvider;
use App\Service\Location\CountryReadinessService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The CONTENT check, after it stopped being a deploy-shaped blocker.
 *
 * It used to read the registry's statics directly and report "missing from
 * CountryContentRegistry" — a 2,364-line PHP file, which is not something an operator can do
 * anything about. It now reads the provider, so content authored in the admin clears it.
 */
class CountryReadinessContentCheckTest extends TestCase
{
    public function testAnUnauthoredCountryIsBlocked(): void
    {
        $country = $this->unauthoredCountry();
        $check   = $this->contentCheckFor($country, null);

        self::assertFalse($check['ok']);
        self::assertStringContainsString('prestigeSuffixes', $check['detail']);
    }

    /** The message has to name somewhere the operator can actually go. */
    public function testTheFailureNamesTheScreenRatherThanAPhpFile(): void
    {
        $check = $this->contentCheckFor($this->unauthoredCountry(), null);

        self::assertStringContainsString('set it on this screen', $check['detail']);
        self::assertStringNotContainsString('CountryContentRegistry', $check['detail']);
    }

    public function testItNeverMentionsPlaces(): void
    {
        // Place data lives in the location table and is covered by the location checks; the
        // registry's own static still reports it, and this must not pass that through.
        $country = $this->unauthoredCountry();

        self::assertContains('places', CountryContentRegistry::missingCategories($country));
        self::assertStringNotContainsString('places', $this->contentCheckFor($country, null)['detail']);
    }

    public function testAnAuthoredCountryPassesOnTheRegistryAlone(): void
    {
        $check = $this->contentCheckFor(Country::ES, null);

        self::assertTrue($check['ok']);
        self::assertSame('club-naming content authored', $check['detail']);
    }

    /** The whole point: an admin override clears a blocker PHP cannot. */
    public function testAnOverrideClearsIt(): void
    {
        $country = $this->unauthoredCountry();

        $profile = new CountryProfile($country->value, 'Nowhere');
        $profile->setPrestigeSuffixes(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);
        $profile->setGenericSuffixes(['Zeta', 'Eta', 'Theta', 'Iota', 'Kappa']);
        $profile->setStadiumFormats(['%s Park', 'Estadio %s']);

        self::assertTrue($this->contentCheckFor($country, $profile)['ok']);
    }

    public function testACodeThatIsNotACountryCaseStillFailsForItsOwnReason(): void
    {
        // A new country still needs an enum case; that branch is untouched and must stay so.
        $check = $this->contentCheckFor(null, null, 'QQ');

        self::assertFalse($check['ok']);
        self::assertStringContainsString('App\\Enum\\Country case', $check['detail']);
    }

    /** @return array{key: string, ok: bool, detail: string} */
    private function contentCheckFor(?Country $country, ?CountryProfile $profile, ?string $code = null)
    {
        $profiles = $this->createStub(CountryProfileRepository::class);
        $profiles->method('findByCode')->willReturn($profile);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $service = new CountryReadinessService(
            $this->createStub(LeagueRepository::class),
            $this->createStub(LocationRepository::class),
            $this->createStub(StarterConfigRepository::class),
            $connection,
            new CountryNamingContentProvider($profiles),
        );

        foreach ($service->evaluate($code ?? $country->value)['checks'] as $check) {
            if (($check['key'] ?? null) === 'content') {
                return $check;
            }
        }

        self::fail('no content check in the verdict');
    }

    private function unauthoredCountry(): Country
    {
        foreach (Country::cases() as $country) {
            if (CountryContentRegistry::prestigeSuffixes($country) === []) {
                return $country;
            }
        }

        self::fail('every Country case is authored, which this test assumes is not so');
    }
}
