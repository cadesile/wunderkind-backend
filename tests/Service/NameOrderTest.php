<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NameGeneratorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Name order is a per-nationality rule, not a universal "first last".
 *
 * Korean is written surname-first even in English-language football coverage — Son
 * Heung-min, Kim Min-jae — so composing it the default way produces "Heung-min Son", which
 * reads as an error to anyone who follows the game. Japanese shares the native convention
 * but NOT the football one (Takumi Minamino), so it is deliberately excluded; that
 * distinction is the whole reason this is a list rather than an "is it East Asian" guess.
 */
class NameOrderTest extends TestCase
{
    public function testKoreanNamesAreSurnameFirst(): void
    {
        $this->assertSame(
            'Son Heung-min',
            NameGeneratorService::formatFullName('Heung-min', 'Son', 'South Korean'),
        );
    }

    #[DataProvider('givenFirstNationalityProvider')]
    public function testEveryOtherNationalityStaysGivenFirst(string $nationality): void
    {
        $this->assertSame(
            'Given Surname',
            NameGeneratorService::formatFullName('Given', 'Surname', $nationality),
            sprintf('%s must keep given-name-first order', $nationality),
        );
    }

    /** @return array<string, array{string}> */
    public static function givenFirstNationalityProvider(): array
    {
        // Japanese is listed explicitly: it is the case most likely to be "corrected" by
        // someone reasoning from the native convention rather than from football usage.
        $cases = [];
        foreach (['Japanese', 'Chinese', 'English', 'Spanish', 'Brazilian', 'Turkish', 'Saudi', 'Indian'] as $nationality) {
            $cases[$nationality] = [$nationality];
        }

        return $cases;
    }

    /** generatePlayerName() can return an empty surname for Brazilian mononyms. */
    public function testAnEmptySurnameDoesNotLeaveStrayWhitespace(): void
    {
        $this->assertSame('Ronaldinho', NameGeneratorService::formatFullName('Ronaldinho', '', 'Brazilian'));
        $this->assertSame('Heung-min', NameGeneratorService::formatFullName('Heung-min', '  ', 'South Korean'));
    }

    /** The composed string generateName() hands to staff, scouts and agents must obey the rule. */
    public function testGeneratedKoreanStaffNamesAreSurnameFirst(): void
    {
        $service  = new NameGeneratorService();
        $surnames = self::koreanSurnames();

        for ($i = 0; $i < 50; $i++) {
            $parts = explode(' ', $service->generateName('South Korean'));

            $this->assertContains(
                $parts[0],
                $surnames,
                sprintf('"%s" starts with a given name — generateName() is not applying the order rule.', implode(' ', $parts)),
            );
        }
    }

    /** @return list<string> */
    private static function koreanSurnames(): array
    {
        $method = new \ReflectionMethod(NameGeneratorService::class, 'getNamePools');

        return $method->invoke(null)['South Korean']['lastNames'];
    }
}
