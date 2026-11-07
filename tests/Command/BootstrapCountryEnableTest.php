<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Location\CountryProfile;
use App\Enum\Location\CountryProfileStatus;
use App\Repository\Location\CountryProfileRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:country:bootstrap --enable-only, which is the CLI's door to player-visibility.
 *
 * WHY THIS EXISTS. --enable used to append the country to StarterConfig::enabledCountries, a
 * list that was a separate axis from the profile's status — so setting it without a readiness
 * check harmed nothing. Player-visibility IS the LIVE status now, which turned this option
 * into a fourth way to reach LIVE alongside the Countries CRUD screen and the locations
 * dashboard. The other three go through CountryGoLiveService; this pins that the CLI does too,
 * so a half-built country cannot be opened to players from a terminal.
 *
 * --enable-only rather than --enable: they share enableForPlayers() verbatim, and --enable
 * first runs league generation, club generation and two warm commands. Testing the cheap door
 * tests the same gate.
 *
 * San Marino because nothing else in the suite touches it, and because a country with no
 * leagues and no locations fails readiness for reasons that will not change.
 */
class BootstrapCountryEnableTest extends KernelTestCase
{
    private const CODE = 'SM';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    /**
     * A LIVE San Marino left behind would show up in liveCountryCodes() for every later test,
     * so this runs either side of the test rather than only after it.
     */
    private function purge(): void
    {
        self::getContainer()->get(Connection::class)
            ->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::CODE]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function seedProfile(CountryProfileStatus $status = CountryProfileStatus::DRAFT): CountryProfile
    {
        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $profile = new CountryProfile(self::CODE, 'San Marino');
        $profile->setStatus($status);
        $em->persist($profile);
        $em->flush();

        return $profile;
    }

    private function runEnableOnly(): CommandTester
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:country:bootstrap'),
        );

        $tester->execute(['country' => self::CODE, '--enable-only' => true]);

        return $tester;
    }

    private function statusOnDisk(): ?CountryProfileStatus
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(CountryProfileRepository::class)
            ->findByCode(self::CODE)?->getStatus();
    }

    /** The whole point: an unready country is refused, not promoted. */
    public function testItRefusesToEnableACountryThatIsNotReady(): void
    {
        $this->seedProfile();

        $output = $this->runEnableOnly()->getDisplay();

        self::assertStringContainsString('cannot go live yet', $output);
        self::assertStringContainsString('app:country:check SM', $output);
        self::assertSame(
            CountryProfileStatus::DRAFT,
            $this->statusOnDisk(),
            '--enable-only must not promote a country the readiness checks reject',
        );
    }

    /** Refusing must not stamp the timestamps either — they are "first reached" markers. */
    public function testARefusedEnableLeavesTheReadinessTimestampsUnset(): void
    {
        $this->seedProfile();

        $this->runEnableOnly();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $profile = self::getContainer()->get(CountryProfileRepository::class)->findByCode(self::CODE);

        self::assertNull($profile->getLocationsLiveAt());
        self::assertNull($profile->getTierStructureReadyAt());
    }

    /** Idempotent: re-running against a live country reports, and writes nothing new. */
    public function testEnablingACountryThatIsAlreadyLiveIsANoOp(): void
    {
        $this->seedProfile(CountryProfileStatus::LIVE);

        $output = $this->runEnableOnly()->getDisplay();

        self::assertStringContainsString('already live', $output);
        self::assertSame(CountryProfileStatus::LIVE, $this->statusOnDisk());
    }

    /** No profile at all is a different refusal, and must not create one. */
    public function testACountryWithNoProfileCannotBeEnabled(): void
    {
        $output = $this->runEnableOnly()->getDisplay();

        self::assertStringContainsString('no country profile', $output);
        self::assertNull($this->statusOnDisk());
    }
}
