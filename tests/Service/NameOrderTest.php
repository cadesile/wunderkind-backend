<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\NamePoolProvider;
use App\Service\CountryContent\NamePoolRegistry;
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
        $service  = $this->shippedPoolService();
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


    /**
     * The service over the SHIPPED pools.
     *
     * A stubbed repository returns null from findByCode() and an empty override set, so the
     * provider resolves to NamePoolRegistry — which is what these tests mean by "the pool".
     * Keeping it a plain TestCase matters: a fact about what ships must be assertable without
     * a kernel or a database.
     */
    private function shippedPoolService(): NameGeneratorService
    {
        $repo = $this->createStub(CountryProfileRepository::class);
        $repo->method('findByCode')->willReturn(null);
        $repo->method('namePoolOverrides')->willReturn([]);

        return new NameGeneratorService(new NamePoolProvider($repo));
    }

    /**
     * The SHIPPED Korean surnames, deliberately — not whatever a database holds.
     *
     * This used to reach in with ReflectionMethod on a private method, which coupled the
     * fixture to that method's name and to the whole-array shape; a rename failed it with an
     * array-key notice rather than anything useful. It asks the registry now.
     *
     * It must stay the registry rather than the resolved view: an operator overriding Korean
     * surnames would otherwise fail an assertion that is about NAME ORDER and has nothing to
     * do with which names are in the pool.
     *
     * @return list<string>
     */
    private static function koreanSurnames(): array
    {
        return NamePoolRegistry::lastNames('South Korean');
    }
}
