<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the filter rail is allowed to put in the query string, pinned against EasyAdmin.
 *
 * THE BUG THESE EXIST FOR. Every facet in _bmc_rail.html.twig renders a hidden `comparison`
 * input, and the rail's submit handler used to drop only inputs whose VALUE was empty — which
 * a hidden input never is. So every facet the operator had not touched still posted
 * `filters[prop][comparison]==` with no value, and ChoiceFilterType reads an EQ with no value
 * as `IS NULL`. On a non-nullable column that matches nothing, so touching any one facet
 * emptied the entire list. It was reported on Players and Investors; it was in the shell, so
 * it affected all fifteen redesigned list pages.
 *
 * The fix is client-side — the rail now disables a whole facet the operator left alone — so
 * these tests cannot exercise it directly. What they CAN do is pin the server half of the
 * contract the fix relies on: that a valueless comparison is destructive, and that a properly
 * formed one filters. If an EasyAdmin upgrade ever makes the first harmless, the second is
 * still the behaviour we want and this file says so.
 */
class FilterRailQueryStringTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'filter-rail-test-admin@example.com';
    private const SURNAME     = 'Railtest';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purge();
        $this->loginAsAdmin();
        $this->seedPlayers();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function loginAsAdmin(): void
    {
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

    /** Two goalkeepers and one attacker, so a position filter has something to separate. */
    private function seedPlayers(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        foreach ([PlayerPosition::GOALKEEPER, PlayerPosition::GOALKEEPER, PlayerPosition::ATTACKER] as $i => $position) {
            $em->persist(new Player(
                'Rail' . $i,
                self::SURNAME,
                new \DateTimeImmutable('2000-01-01'),
                'English',
                $position,
                RecruitmentSource::YOUTH_INTAKE,
            ));
        }

        $em->flush();
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM player WHERE last_name = :n', ['n' => self::SURNAME]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * Rows on the current list page, counted by their batch-selection checkbox.
     *
     * SCOPED BY SEARCH, NOT BY A BARE LIST. Counting every row on the page passes alone and
     * fails in the full suite: other tests leave players behind, so an unfiltered list is
     * whatever the table happens to hold. `query` runs against setSearchFields(), which
     * includes lastName, so every request here sees this test's three players and nothing
     * else — and the filter still has to do its own work on top.
     */
    private function rowsFor(string $queryString): int
    {
        $url = '/admin/player?query=' . self::SURNAME . ($queryString === '' ? '' : '&' . $queryString);

        $crawler = $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();

        return $crawler->filter('input[name="batchActionEntityIds[]"]')->count();
    }

    public function testOtherPlayersInTheTableCannotSkewTheseCounts(): void
    {
        // Proves the scoping above rather than trusting it: a player this test did not seed
        // must not reach any of the counts, which is exactly how the first version of this
        // file passed alone and failed in the full suite.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Player(
            'Intruder',
            'Outsider',
            new \DateTimeImmutable('2000-01-01'),
            'English',
            PlayerPosition::GOALKEEPER,
            RecruitmentSource::YOUTH_INTAKE,
        ));
        $em->flush();

        try {
            self::assertSame(3, $this->rowsFor(''), 'still only this test\'s three');
            self::assertSame(2, $this->rowsFor(http_build_query([
                'filters' => ['position' => ['comparison' => '=', 'value' => [PlayerPosition::GOALKEEPER->value]]],
            ])), 'the intruder is a keeper too, and still must not count');
        } finally {
            self::getContainer()->get(Connection::class)
                ->executeStatement("DELETE FROM player WHERE last_name = 'Outsider'");
        }
    }

    public function testAnUnfilteredListShowsEverySeededPlayer(): void
    {
        self::assertSame(3, $this->rowsFor(''), 'the baseline the others are measured against');
    }

    public function testAProperlyFormedChoiceFilterNarrowsTheList(): void
    {
        // What the rail sends for a facet the operator HAS used: the comparison and a value.
        self::assertSame(2, $this->rowsFor(http_build_query([
            'filters' => ['position' => ['comparison' => '=', 'value' => [PlayerPosition::GOALKEEPER->value]]],
        ])));
    }

    public function testAComparisonWithNoValueWipesTheListOut(): void
    {
        // What the rail used to send for every facet the operator had NOT used. Asserting the
        // damage rather than hiding it: this is why an untouched facet must not reach the query
        // string at all, and the number here is the whole bug in one line.
        self::assertSame(0, $this->rowsFor(http_build_query([
            'filters' => ['position' => ['comparison' => '=']],
        ])), 'EQ with no value becomes IS NULL, and position is not nullable');
    }

    public function testAnUntouchedFacetContributesNothingWhenItIsOmitted(): void
    {
        // The shape the fixed rail produces: the used facet only, the untouched one absent.
        // Same result as filtering alone, which is the point.
        self::assertSame(2, $this->rowsFor(http_build_query([
            'filters' => ['position' => ['comparison' => '=', 'value' => [PlayerPosition::GOALKEEPER->value]]],
        ])));
    }

    /**
     * The value the rail RENDERS must be the value the filter ACCEPTS.
     *
     * THE SECOND BUG, and the one that made Players fail even with a well-formed query string.
     * PlayerCrudController passed enum *instances* as choice values, and Symfony's ChoiceType
     * cannot derive a scalar from an arbitrary object, so it submits the choice list's INDEX —
     * "0", "1", "2" — while the rail renders the enum's backing value, "GK". The two never met:
     * `value=GK` was silently dropped as an invalid choice and the filter did not apply at all,
     * while `value=0` worked. EasyAdmin warns about exactly this in ChoiceFilter's own docblock.
     *
     * Scraping the checkbox out of the rendered rail rather than hard-coding "GK" is what makes
     * this a real guard: it fails for an index, for a label, and for any other encoding that
     * drifts from what the filter will take.
     */
    public function testTheValueTheRailRendersIsTheValueTheFilterAccepts(): void
    {
        $crawler = $this->client->request('GET', '/admin/player?query=' . self::SURNAME);
        $this->assertResponseIsSuccessful();

        $boxes = $crawler->filter('#bmc-filters input[type=checkbox][name="filters[position][value][]"]');
        self::assertGreaterThan(0, $boxes->count(), 'the position facet should render checkboxes');

        $rendered = $boxes->eq(0)->attr('value');
        self::assertSame(PlayerPosition::GOALKEEPER->value, $rendered, 'first choice is the keeper');

        self::assertSame(2, $this->rowsFor(http_build_query([
            'filters' => ['position' => ['comparison' => '=', 'value' => [$rendered]]],
        ])), 'the rendered value must actually filter');
    }
}
