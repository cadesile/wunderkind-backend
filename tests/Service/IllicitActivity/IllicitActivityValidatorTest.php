<?php

declare(strict_types=1);

namespace App\Tests\Service\IllicitActivity;

use App\Enum\IllicitActivityKind;
use App\Service\IllicitActivity\IllicitActivityValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IllicitActivityValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function record(array $overrides = [], array $details = []): array
    {
        return array_replace([
            'id'          => '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b',
            'kind'        => 'fake_sponsor',
            'startWeek'   => 41,
            'startSeason' => 2,
            'suspicion'   => 64,
            'status'      => 'active',
            'endedWeek'   => null,
            'details'     => array_replace([
                'name'          => 'Northgate Holdings Ltd',
                'weeklyPayment' => 1250000,
                'contractWeeks' => 38,
                'endWeek'       => 79,
                'companySize'   => 'MEDIUM',
                'trigger'       => 'bleed',
            ], $details),
        ], $overrides);
    }

    public function testAValidFakeSponsorIsKeptWithOnlyItsKnownFields(): void
    {
        $raw = self::record(['extra' => 'ignored'], ['secret' => 'ignored']);
        $out = (new IllicitActivityValidator())->validate([$raw]);

        self::assertSame([], $out['dropped']);
        self::assertCount(1, $out['records']);
        $r = $out['records'][0];
        self::assertSame(IllicitActivityKind::FAKE_SPONSOR, $r->kind);
        self::assertSame('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $r->clientId);
        self::assertSame(64, $r->suspicion);
        self::assertSame([
            'name' => 'Northgate Holdings Ltd', 'weeklyPayment' => 1250000, 'contractWeeks' => 38,
            'endWeek' => 79, 'companySize' => 'medium', 'trigger' => 'bleed',
        ], $r->details);
    }

    public function testAnEndedRecordNeedsItsEndedWeek(): void
    {
        $v = new IllicitActivityValidator();
        self::assertCount(1, $v->validate([self::record(['status' => 'ended', 'endedWeek' => 79])])['records']);
        self::assertSame('invalid_ended_week', $v->validate([self::record(['status' => 'ended'])])['dropped'][0]['reason']);
        self::assertSame('invalid_ended_week', $v->validate([self::record(['endedWeek' => 50])])['dropped'][0]['reason']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badRecords(): iterable
    {
        yield 'no id'              => [self::record(['id' => null]), 'invalid_id'];
        yield 'id with spaces'     => [self::record(['id' => 'a b']), 'invalid_id'];
        yield 'unknown kind'       => [self::record(['kind' => 'match_fixing']), 'unknown_kind'];
        yield 'zero start week'    => [self::record(['startWeek' => 0]), 'invalid_start'];
        yield 'string season'      => [self::record(['startSeason' => '2']), 'invalid_start'];
        yield 'suspicion over 100' => [self::record(['suspicion' => 101]), 'invalid_suspicion'];
        yield 'float suspicion'    => [self::record(['suspicion' => 6.5]), 'invalid_suspicion'];
        yield 'bad status'         => [self::record(['status' => 'paused']), 'invalid_status'];
        yield 'no details'         => [self::record(['details' => null]), 'invalid_details'];
        yield 'blank name'         => [self::record([], ['name' => '  ']), 'invalid_details'];
        yield 'long name'          => [self::record([], ['name' => str_repeat('x', 81)]), 'invalid_details'];
        yield 'zero payment'       => [self::record([], ['weeklyPayment' => 0]), 'invalid_details'];
        yield 'bad size'           => [self::record([], ['companySize' => 'HUGE']), 'invalid_details'];
        yield 'bad trigger'        => [self::record([], ['trigger' => 'boredom']), 'invalid_details'];
    }

    #[DataProvider('badRecords')]
    public function testABadRecordIsDroppedOnItsOwn(array $bad, string $reason): void
    {
        $good = self::record(['id' => 'good-1']);
        $out  = (new IllicitActivityValidator())->validate([$bad, $good]);

        self::assertCount(1, $out['records']);
        self::assertSame('good-1', $out['records'][0]->clientId);
        self::assertSame([['index' => 0, 'reason' => $reason]], $out['dropped']);
    }

    public function testDuplicatesAndNonObjectsAreDropped(): void
    {
        $out = (new IllicitActivityValidator())->validate([self::record(), 'nope', self::record()]);
        self::assertCount(1, $out['records']);
        self::assertSame(['not_an_object', 'duplicate_id'], array_column($out['dropped'], 'reason'));
    }

    public function testTheListIsTrimmedToTheCap(): void
    {
        $list = [];
        for ($i = 0; $i < IllicitActivityValidator::MAX_RECORDS + 5; $i++) {
            $list[] = self::record(['id' => 'r' . $i]);
        }
        $out = (new IllicitActivityValidator())->validate($list);

        self::assertCount(IllicitActivityValidator::MAX_RECORDS, $out['records']);
        self::assertSame(5, $out['trimmed']);
    }

    public function testANullListIsNothingAndANonListIsReported(): void
    {
        $v = new IllicitActivityValidator();
        self::assertSame(['records' => [], 'dropped' => [], 'trimmed' => 0], $v->validate(null));
        self::assertSame('not_a_list', $v->validate('x')['dropped'][0]['reason']);
    }
}
