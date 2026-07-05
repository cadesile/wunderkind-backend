<?php

namespace App\Command;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\SeasonRecord;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\TransferType;
use App\Repository\LeagueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds Transfer/SeasonRecord/Club fixture data for exercising the
 * /api/stats/* community stats endpoints locally. Idempotent — deletes
 * its own previously-seeded data (identified by a reserved email/name
 * prefix) before recreating, so relative dates (e.g. "3 days ago") stay
 * fresh on every run.
 */
#[AsCommand(
    name: 'app:seed-community-stats-fixtures',
    description: 'Seeds dummy Club/Transfer/SeasonRecord data to test the /api/stats/* endpoints locally.',
)]
class SeedCommunityStatsFixturesCommand extends Command
{
    private const EMAIL_PREFIX = 'fixture-stats-';
    private const LEAGUE_COUNTRY = 'XX';
    private const LEAGUE_TIER = 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeagueRepository $leagueRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->deletePreviousFixtures($io);

        $league = $this->leagueRepository->findByCountryAndTier(self::LEAGUE_COUNTRY, self::LEAGUE_TIER)
            ?? $this->createLeague();

        $clubs = $this->createClubs();

        $this->seedTransfers($clubs);
        $this->seedSeasonRecords($clubs, $league);

        $this->em->flush();

        $io->success('Seeded community stats fixtures for 5 clubs: '.implode(', ', array_map(
            static fn (Club $c) => $c->getName(),
            $clubs,
        )));
        $io->table(
            ['Club', 'Transfers', 'SeasonRecords', 'Notes'],
            [
                ['Fixture Club A', '4 (1-5 days ago)', '2 (2 & 200 days ago, 1 trophy)', 'All within week/month; season cutoff (2d) trims some'],
                ['Fixture Club B', '3 (10/20/25 days ago)', '1 (15 days ago)', 'Within month but not week; season cutoff (15d) trims 20/25d'],
                ['Fixture Club C', '2 (60/90 days ago)', 'none', 'Outside week/month; no SeasonRecord so season=all for this club'],
                ['Fixture Club D', '2 (3/400 days ago)', '1 (350 days ago, trophy)', 'Season cutoff (350d) excludes the 400-day transfer'],
                ['Fixture Club E', 'none', '2 (1 trophy)', 'Appears in most-seasons/most-trophies only'],
            ],
        );

        return Command::SUCCESS;
    }

    private function deletePreviousFixtures(SymfonyStyle $io): void
    {
        $conn = $this->em->getConnection();
        $params = ['prefix' => self::EMAIL_PREFIX.'%'];
        $clubSubquery = '(SELECT id FROM club WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE :prefix))';

        // Club->User and SeasonRecord->Club are RESTRICT at the DB level (no
        // cascade), so child rows must be deleted before their parents.
        $conn->executeStatement("DELETE FROM season_record WHERE club_id IN {$clubSubquery}", $params);
        $conn->executeStatement("DELETE FROM transfer WHERE club_id IN {$clubSubquery}", $params);
        $conn->executeStatement('DELETE FROM club WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE :prefix)', $params);
        $removed = $conn->executeStatement('DELETE FROM "user" WHERE email LIKE :prefix', $params);

        if ($removed > 0) {
            $io->note("Removed {$removed} previously-seeded fixture user(s) and their clubs/transfers/season records.");
        }
    }

    private function createLeague(): League
    {
        $league = new League(self::LEAGUE_COUNTRY, self::LEAGUE_TIER, 'Fixture League');
        $this->em->persist($league);
        $this->em->flush();

        return $league;
    }

    /** @return array<string, Club> keyed by A/B/C/D/E */
    private function createClubs(): array
    {
        $clubs = [];
        foreach (['A' => 'Fixture Club A', 'B' => 'Fixture Club B', 'C' => 'Fixture Club C', 'D' => 'Fixture Club D', 'E' => 'Fixture Club E'] as $key => $name) {
            $user = new User(self::EMAIL_PREFIX.strtolower($key).'@example.com');
            $user->setPassword('not-a-real-password');
            $this->em->persist($user);

            $club = new Club($name, $user);
            $this->em->persist($club);

            $clubs[$key] = $club;
        }

        $this->em->flush();

        return $clubs;
    }

    /** @param array<string, Club> $clubs */
    private function seedTransfers(array $clubs): void
    {
        $spec = [
            'A' => [
                ['days' => 1, 'dev' => 15, 'fee' => 200000],
                ['days' => 2, 'dev' => 10, 'fee' => 150000],
                ['days' => 3, 'dev' => 20, 'fee' => 300000],
                ['days' => 5, 'dev' => 25, 'fee' => 100000],
            ],
            'B' => [
                ['days' => 10, 'dev' => 8, 'fee' => 50000],
                ['days' => 20, 'dev' => 12, 'fee' => 75000],
                ['days' => 25, 'dev' => 5, 'fee' => 60000],
            ],
            'C' => [
                ['days' => 60, 'dev' => 30, 'fee' => 500000],
                ['days' => 90, 'dev' => 40, 'fee' => 400000],
            ],
            'D' => [
                ['days' => 3, 'dev' => 6, 'fee' => 90000],
                ['days' => 400, 'dev' => 50, 'fee' => 1000000],
            ],
            'E' => [],
        ];

        foreach ($spec as $key => $transfers) {
            foreach ($transfers as $t) {
                $transfer = new Transfer(
                    null,
                    $clubs[$key],
                    'Fixture Buyer FC',
                    TransferType::SALE,
                    new \DateTimeImmutable("-{$t['days']} days"),
                );
                $transfer->setFee($t['fee']);
                $transfer->setNetProceeds((int) ($t['fee'] * 0.9));
                $transfer->setDevelopmentPoints($t['dev']);
                $this->em->persist($transfer);
            }
        }
    }

    /** @param array<string, Club> $clubs */
    private function seedSeasonRecords(array $clubs, League $league): void
    {
        // [clubKey, daysAgo, finalPosition]
        $spec = [
            ['A', 2, 1],
            ['A', 200, 3],
            ['B', 15, 2],
            ['D', 350, 1],
            ['E', 5, 1],
            ['E', 100, 4],
        ];

        $backdates = [];
        foreach ($spec as [$key, $daysAgo, $finalPosition]) {
            $record = new SeasonRecord(
                $clubs[$key],
                $league,
                season: 1,
                finalPosition: $finalPosition,
                gamesPlayed: 38,
                wins: 20,
                draws: 10,
                losses: 8,
                goalsFor: 55,
                goalsAgainst: 30,
                points: 70,
                promoted: false,
                relegated: false,
            );
            $this->em->persist($record);
            $backdates[] = [$record, $daysAgo];
        }

        $this->em->flush();

        $conn = $this->em->getConnection();
        foreach ($backdates as [$record, $daysAgo]) {
            $conn->executeStatement(
                'UPDATE season_record SET created_at = :d WHERE id = :id',
                [
                    'd'  => (new \DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d H:i:s'),
                    'id' => $record->getId()->toRfc4122(),
                ],
            );
        }
        $this->em->clear();
    }
}
