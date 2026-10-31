<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardAwardPlacement;
use App\Entity\User;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * NOTE the helper is called runCloseCommand(), NOT runCommand(). KernelTestCase pulls in
 * ConsoleCommandAssertionsTrait, which declares a STATIC runCommand() — a non-static
 * override of that name is a hard PHP fatal at class-load time, raised during PHPUnit's
 * suite *building*, so it takes down the entire run with exit 255 and zero tests executed.
 * CompetitionProvisionInstancesCommandTest uses runProvisionCommand() for the same reason.
 */
class CloseLeaderboardPeriodsCommandTest extends KernelTestCase
{
    private const CATEGORY = LeaderboardCategory::TRANSFER_SPEND;

    private EntityManagerInterface $em;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testNoEnabledDefinitionIsASuccessThatWritesNothing(): void
    {
        $archivesBefore = $this->archiveCount();

        $tester = $this->runCloseCommand([]);

        self::assertSame(0, $tester->getStatusCode(), '"nothing due" is the normal outcome and must not page anyone');
        self::assertStringContainsString('Nothing due', $tester->getDisplay());
        self::assertSame($archivesBefore, $this->archiveCount());
    }

    public function testDryRunClaimsNothing(): void
    {
        $this->persistDefinitionWithBoard('2017-M05');

        $archivesBefore = $this->archiveCount();

        $tester = $this->runCloseCommand([
            '--dry-run' => true,
            '--at'      => '2017-06-10T00:00:00+00:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringContainsString('2017-M05', $tester->getDisplay());
        self::assertSame($archivesBefore, $this->archiveCount(), 'a dry run claims nothing');
    }

    public function testAnUnknownDefinitionSelectorFails(): void
    {
        $tester = $this->runCloseCommand(['--definition' => 'not-a-category-or-uuid']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('neither a definition id nor a leaderboard', $tester->getDisplay());
    }

    public function testAnUnparseableAtValueFails(): void
    {
        $tester = $this->runCloseCommand(['--at' => 'the day before yesterday-ish']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Could not parse', $tester->getDisplay());
    }

    public function testClosingThroughTheCommandArchivesTheBoard(): void
    {
        $definition = $this->persistDefinitionWithBoard('2017-M06');

        $tester = $this->runCloseCommand([
            '--definition' => (string) $definition->getId(),
            '--at'         => '2017-07-10T00:00:00+00:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('archived', $tester->getDisplay());
        self::assertStringContainsString('1 period(s) closed', $tester->getDisplay());

        $archive = self::getContainer()
            ->get(\App\Repository\Leaderboard\LeaderboardPeriodArchiveRepository::class)
            ->findOneForClaim(self::CATEGORY, '2017-M06');

        self::assertNotNull($archive);
        $this->cleanup[] = $archive;
        self::assertSame(1, $archive->getPlacementsAwarded());
    }

    /** @param array<string, mixed> $input */
    private function runCloseCommand(array $input): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester      = new CommandTester($application->find('app:leaderboards:close-periods'));
        $tester->execute($input);

        return $tester;
    }

    private function persistDefinitionWithBoard(string $period): LeaderboardAwardDefinition
    {
        $definition = new LeaderboardAwardDefinition();
        $definition
            ->setName('Command Test Award')
            ->setCategory(self::CATEGORY)
            ->setCadence(AwardCadence::MONTHLY)
            ->setIsEnabled(true);

        $placement = new LeaderboardAwardPlacement(1);
        $placement->setPrizePence(75_000);
        $definition->addPlacement($placement);

        $this->persist($definition);

        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@cmd.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club('Command FC', $user));
        $club->setCurrentSeason(3);

        $entry = $this->persist(new LeaderboardEntry($club, self::CATEGORY, $period));
        $entry->observeCumulative(10);
        $entry->observeCumulative(410);

        $this->em->flush();

        return $definition;
    }

    private function archiveCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM leaderboard_period_archive');
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
