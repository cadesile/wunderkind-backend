<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\SeasonRecord;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Service\ClubResolver;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET`/`POST /api/club/season-investment`.
 *
 * THREE GROUPS OF ASSERTIONS, IN ORDER OF HOW BADLY THEY WOULD HURT:
 *
 *  1. **Idempotency.** This endpoint spends real account credit and the client retries after a
 *     dropped response, so "the same request twice debits once" is the whole contract — a
 *     regression bills a player twice for one gift and no amount of client-side care prevents
 *     it. Same reason `ClubSetupControllerTest` leads with its own.
 *  2. **The season authority.** The season comes from the backend's `SeasonRecord` count, never
 *     from `club.currentSeason`, because that column is device-mirrored. A client that could
 *     name its own season would both raise its ceiling (it grows with the inflation index) and
 *     unlock windows it never played. The tests below forge `currentSeason` and assert it
 *     changes nothing.
 *  3. **The aggregates.** `UserLedgerRepository`'s dividend sums and the public Rich List both
 *     read this table and filter on type, precisely so a negative purchase row cannot corrupt a
 *     leaderboard. A new debit type is exactly the thing that breaks that, so it is asserted.
 *
 * Plus the boundary every club-scoped write here shares: the endpoint must not write
 * `club.balance`. The device owns that column and overwrites it absolutely on every sync, so a
 * server-side credit would look right until the first sync and then vanish.
 */
class SeasonInvestmentControllerTest extends WebTestCase
{
    private const EMAIL_PREFIX = 'season-investment-';
    private const URI          = '/api/club/season-investment';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private string $currentUserId = '';
    private string $clubId        = '';
    private string $leagueCountry = 'ZS';

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $c                = self::getContainer();
        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);

        $this->purge();
        $this->setBaseCeiling(0);
    }

    protected function tearDown(): void
    {
        $this->purge();
        $this->setBaseCeiling(0);

        parent::tearDown();
    }

    // ── It ships off ──────────────────────────────────────────────────────

    public function testWithNoBaseFigureAuthoredEveryInvestmentIsRefused(): void
    {
        // THE SHIPPED STATE. A base of 0 makes the resolved ceiling 0, so every amount is above
        // it — which is how both new error codes ship unreachable by any client that does not
        // understand them yet.
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 1]);

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('above_season_ceiling', $body['error']);
        self::assertSame(0, $body['ceilingPence']);
        self::assertStringContainsString('not enabled', $body['message']);

        self::assertSame(0, $this->ledgerRowCount(), 'a refused investment must leave no ledger row');
    }

    public function testTheStateReadWorksBeforeAnythingIsAuthored(): void
    {
        // The screen has to render on a club that cannot invest yet, rather than erroring.
        $this->accountWithClub(creditPence: 50_000);

        $this->authedRequest('GET', self::URI);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertFalse($body['invested']);
        self::assertSame(1, $body['season']);
        self::assertSame(0, $body['ceilingPence']);
        self::assertSame(0, $body['creditClubPence']);
    }

    // ── Idempotency ───────────────────────────────────────────────────────

    public function testTheSameAmountTwiceDebitsOnce(): void
    {
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_500_000]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['invested']);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_500_000]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertFalse($body['invested'], 'the replay must not debit again');
        self::assertSame(2_500_000, $body['investedPence']);
        // THE ORIGINAL FIGURE IS STILL RETURNED, so a client that lost the first response can
        // still seed its local balance from the replay.
        self::assertSame(2_500_000, $body['creditClubPence']);

        self::assertSame(1, $this->ledgerRowCount(), 'a retry must not write a second debit');
    }

    public function testADifferentAmountForTheSameSeasonIsAConflict(): void
    {
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_500_000]);
        self::assertResponseIsSuccessful();

        $this->authedRequest('POST', self::URI, ['amountPence' => 3_000_000]);

        self::assertResponseStatusCodeSame(409);
        $body = $this->json();
        self::assertSame('already_invested_this_season', $body['error']);
        // Carries what WAS given and which season, because the client cannot work the season
        // out — the backend decides it.
        self::assertSame(2_500_000, $body['investedPence']);
        self::assertSame(1, $body['season']);

        self::assertSame(1, $this->ledgerRowCount());
    }

    public function testAReplayStillSucceedsAfterTheCeilingIsLowered(): void
    {
        // THE ORDERING THAT MATTERS: the replay check runs BEFORE the ceiling check, so an
        // operator re-tuning the economy cannot turn a client's harmless retry into a permanent
        // failure for a debit that already happened.
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_500_000]);
        self::assertResponseIsSuccessful();

        $this->setBaseCeiling(1_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_500_000]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['invested']);
        self::assertSame(1, $this->ledgerRowCount());
    }

    // ── The season authority ──────────────────────────────────────────────

    public function testTheSeasonComesFromConcludedRecordsNotFromTheClubColumn(): void
    {
        // club.currentSeason is DEVICE-MIRRORED. Forging it must change nothing — otherwise a
        // client could name a season to raise its own ceiling and unlock windows it never
        // played.
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);
        $this->forgeClubCurrentSeason(50);

        $this->authedRequest('GET', self::URI);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['season'], 'a forged currentSeason must be ignored');
    }

    public function testConcludingASeasonOpensExactlyOneMoreWindow(): void
    {
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_000_000]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['invested']);

        // A second gift in the SAME season is refused...
        $this->authedRequest('POST', self::URI, ['amountPence' => 2_000_000]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['invested']);

        // ...until a season is actually concluded, which is the only thing that writes a
        // SeasonRecord.
        $this->concludeASeason(1);

        $this->authedRequest('GET', self::URI);
        self::assertSame(2, $this->json()['season']);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_000_000]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['invested'], 'the new season is a new window');

        self::assertSame(2, $this->ledgerRowCount(), 'one debit per concluded season, no more');
    }

    public function testNoRolloverBetweenSeasons(): void
    {
        // Skipping a season does not bank the allowance — each season's ceiling stands alone.
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->concludeASeason(1);
        $this->concludeASeason(2);

        $this->authedRequest('GET', self::URI);
        $body = $this->json();

        self::assertSame(3, $body['season']);
        self::assertSame(10_000_000, $body['ceilingPence'], 'two skipped seasons do not accumulate');
        self::assertSame(10_000_000, $body['remainingPence']);
    }

    // ── Money ─────────────────────────────────────────────────────────────

    public function testTheDebitIsStoredNegative(): void
    {
        // The sign convention the ledger's balance chaining depends on; append() throws on a
        // positive debit, so this also proves isDebit() is wired.
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_500_000]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            -2_500_000,
            (int) $this->connection->fetchOne(
                "SELECT amount_pence FROM user_ledger WHERE type = 'season_investment' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    public function testItNeverWritesTheClubBalance(): void
    {
        // THE BOUNDARY. The device owns club.balance and overwrites it absolutely on every
        // sync, so a server-side credit would look right until the first sync and then vanish
        // with no authority left to repair it from. `creditClubPence` is the instruction.
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $before = (int) $this->connection->fetchOne('SELECT balance FROM club WHERE id = ?', [$this->clubId]);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_500_000]);
        self::assertResponseIsSuccessful();
        self::assertSame(2_500_000, $this->json()['creditClubPence']);

        self::assertSame(
            $before,
            (int) $this->connection->fetchOne('SELECT balance FROM club WHERE id = ?', [$this->clubId]),
            'the server must not credit the club',
        );
    }

    public function testMoreThanTheBalanceIsRefusedWithTheArithmetic(): void
    {
        $this->setBaseCeiling(100_000_000);
        $this->accountWithClub(creditPence: 500_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 600_000]);

        self::assertResponseStatusCodeSame(402);
        $body = $this->json();
        self::assertSame('insufficient_credit', $body['error']);
        self::assertSame(500_000, $body['balancePence']);
        self::assertSame(600_000, $body['requiredPence']);
        self::assertSame(0, $this->ledgerRowCount());
    }

    public function testOverTheCeilingIsRefusedAndCarriesIt(): void
    {
        // The ceiling is base x countryFactor x tierFactor x index(season), and the app is
        // deliberately never told a country factor — so a refusal without the figure would be
        // a dead end.
        $this->setBaseCeiling(1_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 1_000_001]);

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('above_season_ceiling', $body['error']);
        self::assertSame(1_000_000, $body['ceilingPence']);
        self::assertSame(1_000_001, $body['amountPence']);
        self::assertSame(0, $this->ledgerRowCount());
    }

    public function testExactlyTheCeilingIsAllowed(): void
    {
        $this->setBaseCeiling(1_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 1_000_000]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['invested']);
        self::assertSame(0, $this->json()['remainingPence']);
    }

    public function testAMalformedAmountIsRefusedBeforeAnyDebit(): void
    {
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        foreach ([null, 'lots', 1.5, '-5', ['x']] as $amount) {
            $this->authedRequest('POST', self::URI, ['amountPence' => $amount]);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('amount_required', $this->json()['error']);
        }

        self::assertSame(0, $this->ledgerRowCount());
    }

    public function testZeroIsRefused(): void
    {
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 0]);

        // `0` is a legal integer, so it reaches the service and is refused there as an invalid
        // amount rather than being caught by the body guard.
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_amount', $this->json()['error']);
        self::assertSame(0, $this->ledgerRowCount());
    }

    // ── The aggregates ────────────────────────────────────────────────────

    public function testADebitDoesNotCountAsClubEarnings(): void
    {
        // UserLedgerRepository's dividend sums and the public Rich List both filter on type
        // precisely so a negative purchase row cannot corrupt a leaderboard. A new debit type
        // is exactly the thing that breaks that.
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_500_000]);
        self::assertResponseIsSuccessful();

        self::assertFalse(UserLedgerEntryType::SEASON_INVESTMENT->isClubEarnings());

        $earnings = (int) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(amount_pence), 0) FROM user_ledger WHERE user_id = ? AND type = 'dividend_draw'",
            [$this->currentUserId],
        );

        self::assertSame(0, $earnings, 'an investment must not appear in dividend aggregates');
    }

    public function testTheBalanceFallsByExactlyTheGift(): void
    {
        $this->setBaseCeiling(10_000_000);
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', self::URI, ['amountPence' => 2_500_000]);

        self::assertResponseIsSuccessful();
        self::assertSame(100_000_000 - 2_500_000, $this->json()['balancePence']);
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    private function setBaseCeiling(int $pence): void
    {
        // THE ROW HAS TO BE FORCED INTO EXISTENCE FIRST. The test database is built by
        // doctrine:schema:create and carries no operator content, so `game_config` can be
        // genuinely empty — and then this UPDATE matches zero rows, the first request creates
        // the singleton from entity defaults (base 0), and every assertion fails on a ceiling
        // the seed never wrote. Exactly the trap GameConfigContractValueRandGuardTest hit.
        self::getContainer()->get(\App\Repository\GameConfigRepository::class)->getConfig(flush: true);

        // Then straight SQL. Country and tier factors are left at parity, so the resolved
        // ceiling IS the base — these tests are about the endpoint, not the scaling chain
        // (EconomyScaleResolverTest owns that).
        $this->connection->executeStatement(
            'UPDATE game_config SET economy_season_investment_base_pence = :p',
            ['p' => $pence],
        );
        $this->em->clear();
    }

    private function accountWithClub(int $creditPence): void
    {
        $user = new User(self::EMAIL_PREFIX . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);

        // A LEAGUE IS REQUIRED, not incidental: the ceiling is scaled by the club's TIER, and a
        // club with no league has none — which the service answers with a ceiling of 0.
        $league = $this->em->getRepository(League::class)
            ->findOneBy(['country' => $this->leagueCountry, 'tier' => 4])
            ?? new League($this->leagueCountry, 4, 'Investment Division');
        $this->em->persist($league);

        $club = new Club('Invest FC ' . bin2hex(random_bytes(3)), $user);
        $club->setCountry($this->leagueCountry);
        $club->setCurrentLeague($league);
        $this->em->persist($club);
        $this->em->flush();

        $this->currentUserId = (string) $user->getId();
        $this->clubId        = $club->getId()->toRfc4122();

        if ($creditPence > 0) {
            self::getContainer()->get(UserLedgerService::class)->append(
                $user,
                null,
                UserLedgerEntryType::STARTING_GRANT,
                $creditPence,
                'test',
                'invest-grant-' . bin2hex(random_bytes(5)),
            );
        }
    }

    /**
     * Writes a SeasonRecord the way concludeSeason() does — the only thing that advances the
     * backend's season count.
     */
    private function concludeASeason(int $season): void
    {
        $club   = $this->em->find(Club::class, $this->clubId);
        $league = $club->getCurrentLeague();

        $this->em->persist(new SeasonRecord(
            club: $club,
            league: $league,
            season: $season,
            finalPosition: 5,
            gamesPlayed: 38,
            wins: 15,
            draws: 10,
            losses: 13,
            goalsFor: 45,
            goalsAgainst: 44,
            points: 55,
            promoted: false,
            relegated: false,
        ));
        $this->em->flush();
        $this->em->clear();
    }

    /** Simulates a device reporting a season it has not played. */
    private function forgeClubCurrentSeason(int $season): void
    {
        $this->connection->executeStatement(
            'UPDATE club SET current_season = :s WHERE id = :id',
            ['s' => $season, 'id' => $this->clubId],
        );
        $this->em->clear();
    }

    private function ledgerRowCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM user_ledger WHERE type = 'season_investment' AND user_id = ?",
            [$this->currentUserId],
        );
    }

    /**
     * A fresh, authenticated client for exactly ONE request.
     *
     * loginUser() authenticates a single request against this stateless JWT firewall and the
     * client never recovers, so every request needs a new one — the same constraint
     * ClubSetupControllerTest documents. X-Club-Id is sent because this endpoint resolves
     * through resolveRequiredFromRequest(), which never guesses which save a debit lands on.
     *
     * @param array<string, mixed> $body
     */
    private function authedRequest(string $method, string $uri, array $body = []): void
    {
        self::ensureKernelShutdown();

        $this->client = static::createClient();

        $c                = self::getContainer();
        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);

        $this->client->loginUser($this->em->find(User::class, $this->currentUserId), 'api');

        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ClubResolver::CLUB_ID_HEADER)) => $this->clubId,
            ],
            content: $body === [] ? '' : json_encode($body),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function purge(): void
    {
        $c    = $this->connection;
        $like = self::EMAIL_PREFIX . '%@test.local';

        $c->executeStatement(
            'DELETE FROM season_record WHERE club_id IN (SELECT id FROM club WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?))',
            [$like],
        );
        $c->executeStatement('DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?)', [$like]);
        $c->executeStatement('DELETE FROM club WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?)', [$like]);
        $c->executeStatement('DELETE FROM "user" WHERE email LIKE ?', [$like]);
        $c->executeStatement('DELETE FROM league WHERE country = ?', [$this->leagueCountry]);
    }
}
