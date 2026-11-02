<?php

namespace App\Tests\Service;

use App\Service\SetupTutorialValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The validator's whole job is to make a SILENT client-side drop visible here, so what
 * matters is that each rule the client applies produces a problem — and, just as much, that
 * a valid payload produces none, or the warning becomes noise everyone learns to ignore.
 *
 * Rules mirror `wunderkind-app/src/components/new-game-setup/tutorial.ts`. If that changes,
 * these change.
 */
class SetupTutorialValidatorTest extends TestCase
{
    private static function note(array $over = []): array
    {
        return array_merge([
            'id' => 'n1', 'kind' => 'note',
            'anchor' => ['on' => 'badge'],
            'bodyKey' => 'ngs.tut.note.badge',
        ], $over);
    }

    private static function briefing(array $over = []): array
    {
        return array_merge([
            'id' => 'b1', 'kind' => 'briefing',
            'anchor' => ['before' => 'avatar'],
            'titleKey' => 'ngs.tut.owner.title',
            'bodyKey' => 'ngs.tut.owner.body',
            'pointKeys' => ['ngs.tut.owner.p1'],
            'ctaKey' => 'ngs.tut.cta.gotIt',
        ], $over);
    }

    private static function payload(array $items, mixed $version = 1): array
    {
        return ['version' => $version, 'items' => $items];
    }

    public function testAValidPayloadProducesNoProblems(): void
    {
        $this->assertSame([], SetupTutorialValidator::problems(
            self::payload([self::note(), self::briefing()]),
        ));
    }

    public function testTheShippedDefaultIsClean(): void
    {
        // Guards against the seeded default in StarterConfig drifting out of validity — the
        // one payload every install starts with.
        $default = (new \App\Entity\StarterConfig())->getSetupTutorialConfig();
        $this->assertSame([], SetupTutorialValidator::problems($default));
        $this->assertCount(15, $default['items']);
    }

    public function testEmptyIsReportedAsTheValidOffState(): void
    {
        $problems = SetupTutorialValidator::problems([]);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('valid "off" state', $problems[0]);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function anchorCases(): iterable
    {
        yield 'unknown step'          => [['on' => 'trophy-room'], 'names no step that exists'];
        yield 'two colons'            => [['on' => 'hire:dof:extra'], 'more than one colon'];
        yield 'role on a plain step'  => [['on' => 'badge:dof'], 'which has none'];
        yield 'unknown role'          => [['on' => 'hire:physio'], 'names no role that exists'];
        yield 'not a string'          => [['on' => 7], 'not a step name'];
        yield 'missing entirely'      => [[], 'not a step name'];
    }

    /** @param array<mixed> $anchor */
    #[DataProvider('anchorCases')]
    public function testBadAnchorsAreReported(array $anchor, string $expected): void
    {
        $problems = SetupTutorialValidator::problems(self::payload([self::note(['anchor' => $anchor])]));
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString($expected, implode(' ', $problems));
    }

    public function testAValidRoleSuffixAndABareKindAreBothAccepted(): void
    {
        $this->assertSame([], SetupTutorialValidator::problems(self::payload([
            self::note(['id' => 'a', 'anchor' => ['on' => 'hire:dof']]),
            self::note(['id' => 'b', 'anchor' => ['on' => 'hire']]),
            self::note(['id' => 'c', 'anchor' => ['on' => 'role:chairman']]),
        ])));
    }

    public function testUnknownKindIsReported(): void
    {
        $problems = SetupTutorialValidator::problems(self::payload([self::note(['kind' => 'banner'])]));
        $this->assertStringContainsString('only "note" and "briefing"', implode(' ', $problems));
    }

    public function testDuplicateIdReportsThatTheFirstWins(): void
    {
        $problems = SetupTutorialValidator::problems(self::payload([
            self::note(['id' => 'dupe']),
            self::note(['id' => 'dupe', 'anchor' => ['on' => 'kits']]),
        ]));
        $this->assertStringContainsString('FIRST one wins', implode(' ', $problems));
    }

    public function testMissingRequiredCopyFieldsAreNamed(): void
    {
        $noBody = SetupTutorialValidator::problems(self::payload([self::note(['bodyKey' => ''])]));
        $this->assertStringContainsString('missing bodyKey', implode(' ', $noBody));

        $briefing = self::briefing();
        unset($briefing['titleKey'], $briefing['bodyKey']);
        $both = SetupTutorialValidator::problems(self::payload([$briefing]));
        $this->assertStringContainsString('missing titleKey and bodyKey', implode(' ', $both));
    }

    public function testTheRetiredBareTutPrefixIsCalledOut(): void
    {
        // The keys were renamed `tut.*` -> `ngs.tut.*` at integration. The old spelling
        // resolves to nothing, and the client drops the item without a word.
        $problems = SetupTutorialValidator::problems(self::payload([
            self::note(['bodyKey' => 'tut.note.badge']),
        ]));
        $this->assertStringContainsString('not under `ngs.tut.`', implode(' ', $problems));
    }

    public function testANonNumericVersionIsReportedBecauseItFloorsToZero(): void
    {
        $problems = SetupTutorialValidator::problems(self::payload([self::note()], '1'));
        $this->assertStringContainsString('not a number', implode(' ', $problems));
        $this->assertStringContainsString('STRING', implode(' ', $problems));
    }

    public function testAnUnknownModeIsReportedAsWIDENINGRatherThanNarrowing(): void
    {
        // The trap: unknown values are filtered out, and an empty result means "all modes".
        // So a typo shows the item everywhere instead of hiding it.
        $problems = SetupTutorialValidator::problems(self::payload([
            self::note(['modes' => ['frist']]),
        ]));
        $this->assertStringContainsString('widens rather than narrows', implode(' ', $problems));
    }

    public function testValidModesPass(): void
    {
        $this->assertSame([], SetupTutorialValidator::problems(self::payload([
            self::note(['modes' => ['first', 'newClub']]),
        ])));
    }

    public function testNonBoolDismissibleIsReported(): void
    {
        $problems = SetupTutorialValidator::problems(self::payload([self::note(['dismissible' => 'yes'])]));
        $this->assertStringContainsString('Only an explicit `false`', implode(' ', $problems));
    }

    public function testItemsNotAnArrayDropsEverything(): void
    {
        $problems = SetupTutorialValidator::problems(['version' => 1, 'items' => 'nope']);
        $this->assertStringContainsString('the whole tutorial is dropped', implode(' ', $problems));
    }

    public function testWhenNothingSurvivesThatIsSaidPlainly(): void
    {
        $problems = SetupTutorialValidator::problems(self::payload([self::note(['kind' => 'banner'])]));
        $this->assertStringContainsString('NOTHING survives', implode(' ', $problems));
    }
}
