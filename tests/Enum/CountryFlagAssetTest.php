<?php

namespace App\Tests\Enum;

use App\Enum\Country;
use PHPUnit\Framework\TestCase;

/**
 * Every Country case must have a flag SVG under public/assets/flags/, because the
 * Owner Spotlight builds its <img src> straight from the lower-cased case name
 * ({{ asset('assets/flags/' ~ code|lower ~ '.svg') }}) with no existence check and
 * no fallback chip. A missing file is therefore not a caught error — it is a broken
 * image on the public landing page.
 *
 * Adding a Country case is the moment that happens, and nothing else in the suite
 * would notice, so this test exists purely to turn "someone added a country" into a
 * red build with the fix written in the failure message. See the flags directory's
 * README for where to source one.
 */
class CountryFlagAssetTest extends TestCase
{
    private const FLAG_DIR = __DIR__ . '/../../public/assets/flags';

    public function testEveryCountryCaseHasAFlagSvg(): void
    {
        $missing = [];

        foreach (Country::cases() as $country) {
            $file = self::FLAG_DIR . '/' . strtolower($country->value) . '.svg';
            if (!is_file($file)) {
                $missing[] = sprintf('%s → %s.svg', $country->name, strtolower($country->value));
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Country cases with no flag asset: %s.\nAdd the SVG to public/assets/flags/ "
            . "named after the Country code, lower-cased — see that directory's README.",
            implode(', ', $missing)
        ));
    }

    /**
     * A zero-byte or HTML file would still satisfy is_file() above while rendering as a
     * broken image — a real possibility given these were fetched over the network, where
     * an error page returns 200 with HTML in it.
     */
    public function testEveryFlagAssetIsActuallyAnSvg(): void
    {
        foreach (Country::cases() as $country) {
            $file = self::FLAG_DIR . '/' . strtolower($country->value) . '.svg';
            if (!is_file($file)) {
                continue; // reported by the test above; not worth failing twice
            }

            $head = (string) file_get_contents($file, false, null, 0, 512);
            $this->assertMatchesRegularExpression(
                '/<svg[\s>]/i',
                $head,
                sprintf('%s flag (%s) does not look like an SVG document', $country->name, basename($file))
            );
        }
    }
}
