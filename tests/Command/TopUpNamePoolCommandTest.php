<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\NamePoolValidator;
use App\Service\CountryContent\Suggestion\NamePoolSuggesterInterface;
use App\Tests\Support\FakeNamePoolSuggester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:names:top-up — the CLI twin of the dashboard's TOP UP WITH AI button.
 *
 * It exists so the prompt can be iterated on without clicking, and --dry-run is the whole
 * point of that: it must report the shortfall and reach no provider. The suggester is faked;
 * no test here may make a real, billed call.
 */
class TopUpNamePoolCommandTest extends KernelTestCase
{
    /** A real Country case that ships with no name pool, touched by nothing else. */
    private const CODE = 'FO';

    private CommandTester $tester;

    private FakeNamePoolSuggester $suggester;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->suggester = new FakeNamePoolSuggester();
        self::getContainer()->set(NamePoolSuggesterInterface::class, $this->suggester);

        $this->purge();

        $this->tester = new CommandTester(
            (new Application(self::$kernel))->find('app:names:top-up'),
        );
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

    /** THE REASON --dry-run EXISTS: it must cost nothing. */
    public function testDryRunReportsTheShortfallAndReachesNoProvider(): void
    {
        $this->tester->execute(['country' => self::CODE, '--dry-run' => true]);

        $this->tester->assertCommandIsSuccessful();
        $output = $this->tester->getDisplay();

        self::assertStringContainsString('would ask for ' . NamePoolValidator::TARGET_PER_LIST, $output);
        self::assertStringContainsString('nothing was asked for and nothing was written', $output);
        self::assertSame(0, $this->suggester->calls());
        self::assertNull($this->profile()?->getFirstNames());
    }

    public function testItWritesBothListsAndPrintsWhatItStored(): void
    {
        $this->suggester
            ->answerWith('firstNames', 150, 'Given')
            ->answerWith('lastNames', 150, 'Family');

        $this->tester->execute(['country' => self::CODE]);

        $this->tester->assertCommandIsSuccessful();

        $profile = $this->profile();
        self::assertCount(150, $profile->getFirstNames());
        self::assertCount(150, $profile->getLastNames());

        // The names are printed because they are culture, not fact — the operator has to be
        // able to read them without opening a database client.
        self::assertStringContainsString('Given1', $this->tester->getDisplay());
    }

    public function testOneListCanBeToppedUpOnItsOwn(): void
    {
        $this->suggester->answerWith('lastNames', 150, 'Family');

        $this->tester->execute(['country' => self::CODE, '--list' => 'lastNames']);

        $this->tester->assertCommandIsSuccessful();
        self::assertSame(1, $this->suggester->calls());
        self::assertSame('lastNames', $this->suggester->lastAsk()['list']);
        self::assertNull($this->profile()->getFirstNames());
    }

    /**
     * A country with no profile row gets one.
     *
     * Name pools hang off country_profile, and app:locations:seed cannot create a row for a
     * country the place registry has no towns for — which is every newly added country, and
     * exactly the case this feature serves. Without this the command would refuse the only
     * countries that need it.
     */
    public function testACountryWithNoProfileRowGetsOne(): void
    {
        self::assertNull($this->profile(), 'precondition: no row');

        $this->suggester->answerWith('firstNames', 150, 'Given')->answerWith('lastNames', 150, 'Family');
        $this->tester->execute(['country' => self::CODE]);

        self::assertNotNull($this->profile());
    }

    public function testACountryAtTargetAsksForNothing(): void
    {
        $this->suggester->answerWith('firstNames', 150, 'Given')->answerWith('lastNames', 150, 'Family');
        $this->tester->execute(['country' => self::CODE]);

        $calls = $this->suggester->calls();
        $this->tester->execute(['country' => self::CODE]);

        self::assertSame($calls, $this->suggester->calls(), 'a second run must be free');
        self::assertStringContainsString('already at target', $this->tester->getDisplay());
    }

    public function testAnUnknownCountryFailsBeforeAnythingIsAsked(): void
    {
        self::assertSame(1, $this->tester->execute(['country' => 'ZZ']));
        self::assertSame(0, $this->suggester->calls());
    }

    public function testAnUnknownListFailsBeforeAnythingIsAsked(): void
    {
        self::assertSame(1, $this->tester->execute(['country' => self::CODE, '--list' => 'middleNames']));
        self::assertSame(0, $this->suggester->calls());
    }

    public function testNoProviderIsAClearFailureRatherThanASilentNoOp(): void
    {
        $this->suggester->setConfigured(false);

        self::assertSame(1, $this->tester->execute(['country' => self::CODE]));
        self::assertStringContainsString('No AI provider is configured', $this->tester->getDisplay());
    }

    /**
     * --dry-run must work with no provider configured.
     *
     * It calls nothing, so refusing it buys nothing — and it is the first thing you want on a
     * developer machine, where the key deliberately is not set (keys live in deploy secrets,
     * never in `.env`).
     */
    public function testDryRunStillReportsTheShortfallWithNoProviderConfigured(): void
    {
        $this->suggester->setConfigured(false);

        $this->tester->execute(['country' => self::CODE, '--dry-run' => true]);

        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('would ask for', $this->tester->getDisplay());
    }

    private function profile(): ?\App\Entity\Location\CountryProfile
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(CountryProfileRepository::class)->findByCode(self::CODE);
    }
}
