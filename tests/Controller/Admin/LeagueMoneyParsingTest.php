<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\LeagueAdminController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pounds as an operator types them, to pence.
 *
 * THIS PARSER HAS BEEN WRONG TWICE, IN THE SAME DIRECTION BOTH TIMES — silently storing less
 * money than was typed, on fields the screen formats with a currency symbol and thousands
 * separators.
 *
 *  1. The quick-edit form bypassed it entirely and cast with a bare `(int)`, which stops at the
 *     first non-digit: "1,000" became 1 and saved £10. Fixed by routing all three money fields
 *     through here.
 *  2. The parser itself then cast with `(int)`, which truncates at the decimal point: "1000.50"
 *     saved £1,000.00 and lost the fifty pence. That is this file's reason to exist.
 *
 * Neither failure announces itself. The form reports a successful save and shows a legal figure;
 * the only symptom is a league quietly worth less than the operator set it to. So the exact
 * inputs an admin screen can produce are pinned here, including the ones that look like typos.
 *
 * Tested through reflection because the method is private and has no business being public —
 * it is an implementation detail of two actions on one controller, and making it public to test
 * it would invite a third caller that does not go through the form's own normalisation.
 */
class LeagueMoneyParsingTest extends TestCase
{
    /** @return array<string, array{string|null, int|null}> */
    public static function moneyProvider(): array
    {
        return [
            // The case that was losing money.
            'decimal pounds keep their pence' => ['1000.50', 100_050],
            'a half penny rounds, not truncates' => ['0.005', 1],
            'decimal with separators'         => ['1,000.50', 100_050],

            // What the screen renders and posts back.
            'thousands separators'            => ['1,000', 100_000],
            'millions'                        => ['500,000,000', 50_000_000_000],
            'surrounding whitespace'          => ['  2,500  ', 250_000],

            // Blank is "no figure", which is a real and different state from zero.
            'empty string is null'            => ['', null],
            'whitespace only is null'         => ['   ', null],
            'absent field is null'            => [null, null],

            // A deliberate zero survives: "a TV deal of nothing" is a legitimate setting that
            // LeagueTierDefaultDiff compares strictly against "no TV deal".
            'zero is a figure, not absence'   => ['0', 0],

            // Garbage is NOT a figure. The old body gave 0, which stored a deliberate-looking
            // "nothing" from a typo.
            'letters are null, not zero'      => ['abc', null],
            'a stray currency symbol is null' => ['£1,000', null],
        ];
    }

    #[DataProvider('moneyProvider')]
    public function testPoundsBecomePence(?string $raw, ?int $expected): void
    {
        self::assertSame($expected, $this->parse($raw));
    }

    public function testEveryPenceValueIsAWholeNumber(): void
    {
        // The column is bigint. A float escaping here would be silently truncated by Doctrine
        // rather than rejected, so the return type is asserted rather than assumed.
        foreach (['1000.50', '1000.55', '0.01', '0.999'] as $raw) {
            self::assertIsInt($this->parse($raw), $raw);
        }
    }

    private function parse(?string $raw): ?int
    {
        $method = new \ReflectionMethod(LeagueAdminController::class, 'parseMoneyToPence');

        return $method->invoke(
            (new \ReflectionClass(LeagueAdminController::class))->newInstanceWithoutConstructor(),
            $raw,
        );
    }
}
