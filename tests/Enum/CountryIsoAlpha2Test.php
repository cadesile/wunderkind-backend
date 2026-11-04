<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Country;
use PHPUnit\Framework\TestCase;

/**
 * Country::isoAlpha2() — the mapping that lets a client get a REAL country code out of this
 * enum's football codes.
 *
 * The cases that matter are the five where `value` is not ISO, and two of those are the sharp
 * ones: SC and NI are valid ISO codes belonging to Seychelles and Nicaragua, so a client
 * deriving a country from `value` does not get a missing answer, it gets a confidently WRONG
 * one. These assertions are what keep that mapping from being "tidied" away.
 */
class CountryIsoAlpha2Test extends TestCase
{
    public function testTheFourHomeNationsMapToGb(): void
    {
        $this->assertSame('GB', Country::EN->isoAlpha2(), 'England has no ISO code of its own');
        $this->assertSame('GB', Country::SC->isoAlpha2(), 'ISO SC is Seychelles');
        $this->assertSame('GB', Country::WA->isoAlpha2());
        $this->assertSame('GB', Country::NI->isoAlpha2(), 'ISO NI is Nicaragua');
    }

    public function testKosovoHasNoIsoCodeAtAll(): void
    {
        // XK is user-assigned. Returning RS would assert Serbian sovereignty over Kosovo, which
        // is a political claim this codebase has no business making in a flag lookup.
        $this->assertNull(Country::XK->isoAlpha2());
    }

    public function testEveryOtherCaseIsItsOwnCode(): void
    {
        $exceptions = [Country::EN, Country::SC, Country::WA, Country::NI, Country::XK];

        foreach (Country::cases() as $case) {
            if (in_array($case, $exceptions, true)) {
                continue;
            }

            $this->assertSame(
                $case->value,
                $case->isoAlpha2(),
                sprintf('%s should map to itself', $case->value),
            );
        }
    }

    public function testIsIsoCodeAgreesWithTheMapping(): void
    {
        foreach (Country::cases() as $case) {
            $this->assertSame(
                $case->isoAlpha2() === $case->value,
                $case->isIsoCode(),
                sprintf('%s: isIsoCode must not disagree with isoAlpha2', $case->value),
            );
        }

        $this->assertFalse(Country::EN->isIsoCode());
        $this->assertTrue(Country::IT->isIsoCode());
    }

    public function testEveryReturnedCodeIsTwoUppercaseLetters(): void
    {
        foreach (Country::cases() as $case) {
            $iso = $case->isoAlpha2();

            if ($iso === null) {
                continue;
            }

            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $iso, $case->value);
        }
    }
}
