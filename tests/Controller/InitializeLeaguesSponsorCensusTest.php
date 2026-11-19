<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\Sponsor;
use App\Entity\User;
use App\Enum\CompanySize;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sponsor CENSUS on `GET /api/initialize/leagues` — `sponsorCount` and `sponsorSizes`.
 *
 * ## Why these keys exist, which is the whole point of the test
 *
 * At v11 all money left the world pack, `sponsorPot` included. This endpoint was already the
 * device's source for the other three league money figures (`tvDeal`, `prizeMoney`,
 * `leaguePositionPot` — uncached and resolved live), but it never carried a pot, so dropping
 * the pack's key with no other change would have zeroed league sponsorship.
 *
 * The resolution was NOT to publish a pot here. The device already rolls one:
 * `rollLeagueSponsorPot` (app `utils/leagueFinances.ts`) draws each sponsor from the
 * DIVISION's published sponsor bands, and `SeasonTransitionService` has called it at every
 * season boundary for months — season 1 was the only one taking a server figure instead. What
 * the device lacked was census data: how many sponsors this league has and what size they are.
 *
 * So these two keys are the same category as `clubCount`, which this endpoint already
 * returned. A count is not a price.
 *
 * ⚠️ **The failure mode being guarded against is a silent `?? 0`.** With no count published,
 * the app's upsert would fall back to zero and the pot would roll to nothing. That hard-zero
 * has a history on this exact field — season-1 position prizes paid at the full pot for every
 * finishing position — which is why the fix was to supply the count rather than to tolerate
 * its absence.
 */
class InitializeLeaguesSponsorCensusTest extends WebTestCase
{
    /** A country of its own, so these assertions never depend on the shared DB's EN rows. */
    private const COUNTRY = 'QS';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<object> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());

            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }

        $this->em->flush();
        $this->cleanup = [];

        parent::tearDown();
    }

    /** The authored column, published as-is — this is what the app's fallback path rolls on. */
    public function testTheLeagueListCarriesTheAuthoredSponsorCount(): void
    {
        $this->seedLeague(sponsorCount: 7);

        $league = $this->firstLeague();

        self::assertArrayHasKey('sponsorCount', $league);
        self::assertSame(7, $league['sponsorCount']);
    }

    /**
     * The ACTUAL mix, counted off this league's `LeagueSponsor` rows and upper-cased.
     *
     * Preferred by the app over the bare count, because it makes the device's roll draw from
     * the same bands for the same sponsors the server would have rolled — rather than guessing
     * a size mix from the league's reputation tier, which is what the count-only path does.
     *
     * UPPER-CASED AT THIS BOUNDARY: `App\Enum\CompanySize` is lower-case ('small'), the app's
     * `CompanySize` union is upper-case ('SMALL'), and the app indexes its band table by it
     * directly. Converting here rather than on the device keeps one translation in one place.
     */
    public function testSponsorSizesCountsTheRealRosterWithAppCasedKeys(): void
    {
        $this->seedLeague(sponsorCount: 3, sizes: [
            CompanySize::SMALL,
            CompanySize::SMALL,
            CompanySize::LARGE,
        ]);

        $league = $this->firstLeague();

        self::assertSame(['SMALL' => 2, 'LARGE' => 1], $league['sponsorSizes']);
    }

    /**
     * NULL, NOT AN EMPTY OBJECT, when a league has no sponsor rows.
     *
     * The app treats a present `sponsorSizes` as authoritative and an absent one as "fall back
     * to `sponsorCount`". An empty object would read as "this league has no sponsors" and roll
     * a pot of nothing — wrong for a league whose count is authored but whose sponsors have not
     * been sourced yet, which is the normal state of a freshly seeded country.
     */
    public function testSponsorSizesIsNullRatherThanEmptyWhenNoSponsorsAreSourced(): void
    {
        $this->seedLeague(sponsorCount: 4);

        $league = $this->firstLeague();

        self::assertNull($league['sponsorSizes']);
        self::assertSame(4, $league['sponsorCount'], 'the count is still there to roll on');
    }

    /**
     * ⚠️ AND STILL NO POT. The device rolls it; publishing one here would put the two sources
     * for one quantity back, which is the defect v11 removed.
     */
    public function testTheEndpointPublishesNoSponsorPot(): void
    {
        $this->seedLeague(sponsorCount: 2, sizes: [CompanySize::MEDIUM, CompanySize::MEDIUM]);

        self::assertArrayNotHasKey('sponsorPot', $this->firstLeague());
    }

    /** @return array<string, mixed> */
    private function firstLeague(): array
    {
        $club   = $this->initializedClub();
        $userId = $club->getUser()->getId();

        // Single-use login against the stateless JWT firewall — see CLAUDE.md.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->client->loginUser($this->em->find(User::class, $userId), 'api');
        $this->client->request('GET', '/api/initialize/leagues');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNotEmpty($payload['leagues'], 'the seeded league must be in the response');

        return $payload['leagues'][0];
    }

    /** @param list<CompanySize> $sizes */
    private function seedLeague(int $sponsorCount, array $sizes = []): void
    {
        $league = new League(self::COUNTRY, 8, 'Sponsor Census League');
        $league->setSponsorCount($sponsorCount);

        foreach ($sizes as $i => $size) {
            $sponsor = new Sponsor('Census Co ' . $i . ' ' . bin2hex(random_bytes(3)));
            $sponsor->setSize($size);
            $this->em->persist($sponsor);
            $this->cleanup[] = $sponsor;

            // addSponsor() creates the LeagueSponsor join row, which is what the endpoint counts.
            $league->addSponsor($sponsor);
        }

        $this->em->persist($league);
        $this->cleanup[] = $league;

        $this->em->flush();
    }

    private function initializedClub(): Club
    {
        $user = new User('leagues-sponsorcensus-' . bin2hex(random_bytes(6)) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->cleanup[] = $user;

        // `starterInitializedAt` is the precondition the endpoint checks — without it the call
        // is a 412 and the assertions above would be testing the error path.
        $club = new Club('Sponsor Census FC', $user);
        $club->setCountry(self::COUNTRY);
        $club->setStarterInitializedAt(new \DateTimeImmutable());
        $this->em->persist($club);
        $this->cleanup[] = $club;

        $this->em->flush();

        return $club;
    }
}
