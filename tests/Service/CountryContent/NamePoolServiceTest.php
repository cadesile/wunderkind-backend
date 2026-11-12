<?php

declare(strict_types=1);

namespace App\Tests\Service\CountryContent;

use App\Entity\Location\CountryProfile;
use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\NamePoolProvider;
use App\Service\CountryContent\NamePoolRegistry;
use App\Service\CountryContent\NamePoolService;
use App\Service\CountryContent\NamePoolValidator;
use App\Service\CountryContent\Suggestion\NamePoolUnavailableException;
use App\Tests\Support\FakeNamePoolSuggester;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The write path: what gets stored, what gets asked for, and what is deliberately NOT stored.
 *
 * No kernel and no database — the entity is held by hand and the flush is a mock, because
 * every decision here is about the value that reaches the setter.
 */
class NamePoolServiceTest extends TestCase
{
    /** Ships with 150/150. */
    private const AUTHORED = 'English';

    public function testSaveStoresWhatTheOperatorTyped(): void
    {
        $albania = $this->profile('AL', 'Albania');
        $service = $this->service();

        $verdict = $service->save($albania, [
            'firstNames' => $this->names(12, 'First'),
            'lastNames'  => $this->names(12, 'Last'),
        ]);

        self::assertSame([], $verdict->rejected);
        self::assertSame('First1', $albania->getFirstNames()[0]);
        self::assertCount(12, $albania->getLastNames());
    }

    /**
     * THE RULE MOST LIKELY TO BE DROPPED IN A REWRITE.
     *
     * Opening England's form and pressing Save does nothing and should look like nothing. If
     * the identical list were stored, the country would silently move from "follows the
     * shipped pool" to "pinned to a copy of it as it was that day", and a later pull request
     * adding English names would never reach it.
     */
    public function testAListIdenticalToTheShippedOneIsStoredAsNullRatherThanACopy(): void
    {
        $england = $this->profile('EN', 'England');

        $this->service()->save($england, [
            'firstNames' => NamePoolRegistry::firstNames(self::AUTHORED),
            'lastNames'  => NamePoolRegistry::lastNames(self::AUTHORED),
        ]);

        self::assertNull($england->getFirstNames());
        self::assertNull($england->getLastNames());
    }

    /** Per list, not per country: an operator may override surnames and leave first names be. */
    public function testOneChangedListIsStoredAndTheUntouchedOneStaysOnTheShippedPool(): void
    {
        $england = $this->profile('EN', 'England');

        $this->service()->save($england, [
            'firstNames' => NamePoolRegistry::firstNames(self::AUTHORED),
            'lastNames'  => $this->names(20, 'Surname'),
        ]);

        self::assertNull($england->getFirstNames());
        self::assertCount(20, $england->getLastNames());
    }

    /** A bad paste into one field must not cost the other. */
    public function testARefusedListLeavesTheOtherOneWritten(): void
    {
        $albania = $this->profile('AL', 'Albania');

        $verdict = $this->service()->save($albania, [
            'firstNames' => $this->names(12, 'First'),
            'lastNames'  => ['TooFew'],
        ]);

        self::assertArrayHasKey('lastNames', $verdict->rejected);
        self::assertCount(12, $albania->getFirstNames());
        self::assertNull($albania->getLastNames(), 'the refused list must be left exactly as it was');
    }

    public function testClearPutsTheCountryBackOnTheShippedPools(): void
    {
        $albania = $this->profile('AL', 'Albania');
        $albania->setFirstNames($this->names(12, 'First'));
        $albania->setLastNames($this->names(12, 'Last'));

        $this->service()->clear($albania);

        self::assertNull($albania->getFirstNames());
        self::assertNull($albania->getLastNames());
    }

    /**
     * THE ASK IS THE SHORTFALL, NOT THE TARGET.
     *
     * Asking for 150 when 30 are held would pay for 120 names it then throws away as
     * duplicates — and the exclusion list is what stops the model returning them in the first
     * place.
     */
    public function testTopUpAsksForTheShortfallAndPassesWhatIsHeldAsExclusions(): void
    {
        $england   = $this->profile('EN', 'England');
        $suggester = (new FakeNamePoolSuggester())->answerWith('firstNames', 10, 'Extra');
        $service   = $this->service($suggester, $england);

        $held = \count(NamePoolRegistry::firstNames(self::AUTHORED));

        $service->topUp($england, 'firstNames', $held + 10);

        $ask = $suggester->lastAsk();

        self::assertSame(10, $ask['want']);
        self::assertSame(NamePoolRegistry::firstNames(self::AUTHORED), $ask['exclude']);
    }

    /** Topping up extends; it never replaces. Authored names must survive. */
    public function testTopUpKeepsEveryNameAlreadyHeldAndAppendsTheNewOnes(): void
    {
        $albania   = $this->profile('AL', 'Albania');
        $authored  = ['Arben', 'Besnik'];
        $albania->setFirstNames($authored);
        $suggester = (new FakeNamePoolSuggester())->answerWith('firstNames', 10, 'Extra');
        $service   = $this->service($suggester, $albania);

        $result = $service->topUp($albania, 'firstNames', 12);

        self::assertSame($authored, \array_slice($albania->getFirstNames(), 0, 2), 'the authored names must come through untouched and first');
        self::assertCount(12, $albania->getFirstNames());
        self::assertSame(10, $result['added']);
        self::assertSame(2, $result['held']);
    }

    /**
     * The floor applies to the MERGED list, so a thin reply on an empty country stores nothing
     * and says why.
     *
     * This is deliberate rather than incidental: a pool of six names across five hundred
     * players repeats absurdly, and half a pool is worse than the English fallback it would
     * replace. The operator gets a rejection they can act on — ask again — instead of a
     * success that produced a useless pool. It costs the call that was already made, which is
     * the price of not writing something unusable.
     */
    public function testAReplyTooThinToBeAUsablePoolIsRefusedRatherThanStored(): void
    {
        $albania   = $this->profile('AL', 'Albania');
        $suggester = (new FakeNamePoolSuggester())->answer('firstNames', ['Arben', 'Besnik', 'Gentian']);
        $service   = $this->service($suggester, $albania);

        $result = $service->topUp($albania, 'firstNames', 150);

        self::assertNull($albania->getFirstNames());
        self::assertSame(0, $result['added']);
        self::assertArrayHasKey('firstNames', $result['verdict']->rejected);
        self::assertStringContainsString((string) NamePoolValidator::MIN_PER_LIST, $result['verdict']->rejected['firstNames']);
    }

    /** A country at target costs nothing — no call, no write. */
    public function testACountryAlreadyAtTargetAsksForNothing(): void
    {
        $albania = $this->profile('AL', 'Albania');
        $albania->setFirstNames($this->names(20, 'First'));
        $suggester = new FakeNamePoolSuggester();
        $service   = $this->service($suggester, $albania);

        $result = $service->topUp($albania, 'firstNames', 20);

        self::assertSame(0, $suggester->calls(), 'nothing should be asked for, and nothing billed');
        self::assertSame(0, $result['added']);
    }

    /**
     * The merged list is validated as a whole, not the increment alone.
     *
     * Validating only what came back would dedupe the new names against each other but not
     * against what is held — which is exactly the collision an exclusion list can miss.
     */
    public function testANameTheModelRepeatsIsDroppedAgainstWhatIsAlreadyHeld(): void
    {
        $albania = $this->profile('AL', 'Albania');
        $held = $this->names(11, 'Held');
        $albania->setFirstNames($held);
        // 'held1' repeats what is held, 'GENTIAN' repeats itself within the reply.
        $suggester = (new FakeNamePoolSuggester())->answer('firstNames', ['held1', 'Gentian', 'GENTIAN']);
        $service   = $this->service($suggester, $albania);

        $result = $service->topUp($albania, 'firstNames', 150);

        self::assertSame([...$held, 'Gentian'], $albania->getFirstNames());
        self::assertSame(1, $result['added']);
    }

    /**
     * Diacritics are not duplicates.
     *
     * ClubNameNormalizer would collide these, which is right for club names and silently
     * destructive here. The dedupe is case-folding only.
     */
    public function testNamesDifferingOnlyByAccentAreBothKept(): void
    {
        $turkey    = $this->profile('TR', 'Turkey');
        $held = [...$this->names(10, 'Held'), 'Güneş'];
        $turkey->setFirstNames($held);
        $suggester = (new FakeNamePoolSuggester())->answer('firstNames', ['Gunes', 'Özkan']);
        $service   = $this->service($suggester, $turkey);

        $service->topUp($turkey, 'firstNames', 150);

        self::assertSame([...$held, 'Gunes', 'Özkan'], $turkey->getFirstNames());
    }

    /** A provider fault must surface, not be swallowed into a silent no-op. */
    public function testAProviderFailurePropagates(): void
    {
        $albania   = $this->profile('AL', 'Albania');
        $suggester = (new FakeNamePoolSuggester())->fail(new NamePoolUnavailableException('down'));
        $service   = $this->service($suggester, $albania);

        $this->expectException(NamePoolUnavailableException::class);
        $service->topUp($albania, 'firstNames', 150);
    }

    /**
     * A write must drop the memo, or the operator sees what they had before pressing Save.
     *
     * The provider caches for the life of the process; the admin handler saves and re-renders
     * in one request.
     */
    public function testAWriteFlushesAndThenDropsTheMemoForThatNationality(): void
    {
        $albania  = $this->profile('AL', 'Albania');
        $repo     = $this->createMock(CountryProfileRepository::class);
        // Once before the save and once after it — the second read is the proof forget() ran.
        $repo->expects($this->exactly(2))->method('findByCode')->willReturn($albania);

        $provider = new NamePoolProvider($repo);
        $em       = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = new NamePoolService(
            $provider,
            new NamePoolValidator(),
            new FakeNamePoolSuggester(),
            $em,
            new NullLogger(),
        );

        $provider->firstNames('Albanian');
        $service->save($albania, ['firstNames' => $this->names(12, 'First')]);

        self::assertCount(12, $provider->firstNames('Albanian'));
    }

    public function testSuggesterConfigurationIsReportedForTheAdminToHideTheButton(): void
    {
        self::assertTrue($this->service()->suggesterIsConfigured());
        self::assertFalse(
            $this->service((new FakeNamePoolSuggester())->setConfigured(false))->suggesterIsConfigured(),
        );
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    private function service(?FakeNamePoolSuggester $suggester = null, ?CountryProfile $profile = null): NamePoolService
    {
        $repo = $this->createStub(CountryProfileRepository::class);
        $repo->method('findByCode')->willReturn($profile);
        $repo->method('namePoolOverrides')->willReturn([]);

        return new NamePoolService(
            new NamePoolProvider($repo),
            new NamePoolValidator(),
            $suggester ?? new FakeNamePoolSuggester(),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    private function profile(string $code, string $name): CountryProfile
    {
        return new CountryProfile($code, $name);
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
}
