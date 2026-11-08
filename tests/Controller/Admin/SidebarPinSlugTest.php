<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * <main data-bmc-page> — the slug the sidebar's PINNED and RECENT lists key on.
 *
 * THE BUG THIS PINS. The slug is derived from whichever menu item EasyAdmin marked selected,
 * and EasyAdmin decides that by matching the WHOLE query string. So the moment a custom page
 * carries a parameter of its own — ?country=US&tier=8 on the starter pack check,
 * routeParams[country] on the locations dashboard — nothing is selected, the label folds to an
 * empty string, and the page renders with no data-bmc-page at all. The pin button then
 * silently does nothing, and the page never reaches RECENT.
 *
 * That had already SHIPPED broken on two pages. The locations dashboard is reached with a
 * country every time an operator picks one, so its pin had never worked in practice.
 *
 * THE SLUG MUST ALSO BE THE SAME EITHER WAY. A fallback that produced a different string for
 * the parameterised URL would make one page two pins, which is worse than none.
 */
class SidebarPinSlugTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'pin-slug@example.com';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $this->client->loginUser($admin, 'admin');
    }

    /** @param array<string, mixed> $query */
    private function slugFor(array $query): ?string
    {
        $this->client->request('GET', '/admin', $query);
        self::assertResponseIsSuccessful();

        preg_match('/<main[^>]*>/', (string) $this->client->getResponse()->getContent(), $main);
        preg_match('/data-bmc-page="([^"]*)"/', $main[0] ?? '', $slug);

        return $slug[1] ?? null;
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function parameterisedPages(): iterable
    {
        yield 'starter pack check' => [
            'admin_starter_pack_check',
            ['country' => 'US', 'tier' => 8],
            'starter-pack-check',
        ];
        yield 'locations dashboard' => [
            'admin_locations_dashboard',
            ['routeParams' => ['country' => 'EN']],
            'dashboard',
        ];
        yield 'enrichment' => [
            'admin_location_enrichment',
            ['country' => 'EN'],
            'enrichment',
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parameterisedPages')]
    public function testThePinSurvivesAPagesOwnQueryParameters(string $route, array $params, string $expected): void
    {
        $bare = $this->slugFor(['routeName' => $route]);

        self::assertSame($expected, $bare, 'the bare page must carry its slug');
        self::assertSame(
            $bare,
            $this->slugFor(array_merge(['routeName' => $route], $params)),
            'the same page with its own parameters must carry the SAME slug, not a different one and not none',
        );
    }

    /** A page with no parameters at all must keep working — the fallback must not shadow it. */
    public function testAPageWithoutParametersIsUnaffected(): void
    {
        self::assertSame('worldpack-cache', $this->slugFor(['routeName' => 'admin_worldpack_cache']));
        self::assertSame('configuration', $this->slugFor(['routeName' => 'admin_location_config']));
    }
}
