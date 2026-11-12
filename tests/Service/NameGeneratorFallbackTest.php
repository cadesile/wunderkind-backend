<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\NamePoolProvider;
use App\Service\CountryContent\NamePoolRegistry;
use App\Service\NameGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * All four generators resolve an unknown nationality the same way.
 *
 * THIS IS THE BUG, STATED AS A TEST. The fallback rule was written out five times, and over
 * time one copy diverged: generatePlayerName(), generateFirstName() and generateLastName()
 * fell back to English, while generateName() — used by staff, scouts and agents — threw
 * "Unknown nationality".
 *
 * Nobody noticed because the two paths are exercised by different callers. `app:pool:warm`
 * across all countries generated five hundred players per country happily and then died on
 * the first member of staff, for each of the thirty-nine countries that ship without a pool,
 * and exited non-zero on a run that had otherwise worked.
 *
 * So the assertion that matters is not "it does not throw" but "they AGREE". One copy of the
 * rule cannot diverge; five could, and did.
 */
class NameGeneratorFallbackTest extends TestCase
{
    /** A real Country case that ships with no pool. */
    private const POOLLESS = 'Albanian';

    /** Not a demonym any Country case carries — spoof payloads can pass anything. */
    private const UNKNOWN = 'Atlantean';

    public function testAPoollessNationalityDoesNotThrow(): void
    {
        $service = $this->service();

        self::assertNotSame('', $service->generateName(self::POOLLESS));
        self::assertNotSame('', $service->generateName(self::UNKNOWN));
    }

    public function testAllFourGeneratorsFallBackToTheSamePool(): void
    {
        $service = $this->service();

        $firstNames = NamePoolRegistry::firstNames('English');
        $lastNames  = NamePoolRegistry::lastNames('English');

        for ($i = 0; $i < 25; $i++) {
            $composed = explode(' ', $service->generateName(self::POOLLESS));
            self::assertContains($composed[0], $firstNames, 'generateName() drew outside the fallback pool');

            $player = $service->generatePlayerName(self::POOLLESS);
            self::assertContains($player['firstName'], $firstNames, 'generatePlayerName() disagrees with generateName()');

            self::assertContains($service->generateFirstName(self::POOLLESS), $firstNames);
            self::assertContains($service->generateLastName(self::POOLLESS), $lastNames);
        }
    }

    /**
     * A poolless nationality is not a candidate, so nothing picks one by accident.
     *
     * The fallback is for a nationality a CALLER supplied. getRandomNationality() must still
     * never hand one out — that is what kept the old throw from firing constantly.
     */
    public function testRandomSelectionStillNeverReturnsAPoollessNationality(): void
    {
        $service = $this->service();

        for ($i = 0; $i < 100; $i++) {
            self::assertNotSame(self::POOLLESS, $service->getRandomNationality());
        }
    }

    /** An override is what makes the fallback stop applying — the feature, end to end. */
    public function testAnAuthoredPoolReplacesTheFallback(): void
    {
        $repo = $this->createStub(CountryProfileRepository::class);
        $repo->method('namePoolOverrides')->willReturn([
            'AL' => ['firstNames' => ['Arben', 'Besnik'], 'lastNames' => ['Hoxha', 'Krasniqi']],
        ]);
        $repo->method('findByCode')->willReturn(null);

        $provider = new NamePoolProvider($repo);
        $provider->pooledNationalities(); // loads the whole override set in one query
        $service = new NameGeneratorService($provider);

        for ($i = 0; $i < 25; $i++) {
            self::assertContains($service->generateFirstName(self::POOLLESS), ['Arben', 'Besnik']);
        }
    }

    private function service(): NameGeneratorService
    {
        $repo = $this->createStub(CountryProfileRepository::class);
        $repo->method('findByCode')->willReturn(null);
        $repo->method('namePoolOverrides')->willReturn([]);

        return new NameGeneratorService(new NamePoolProvider($repo));
    }
}
