<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Club;
use App\Entity\DeletionRequest;
use App\Entity\User;
use App\Enum\DeletionRequestStatus;
use App\Enum\UserLedgerEntryType;
use App\Repository\GameConfigRepository;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:users:prune-inactive deletes whole accounts, so most of these tests are the negative
 * ones: anything that ever played, holds credit, was seen recently or is protected survives.
 */
class PruneInactiveAccountsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->purge();
        $this->setMode('live');
    }

    protected function tearDown(): void
    {
        $this->purge();
        $this->setMode('off');
        parent::tearDown();
    }

    public function testANeverPlayedAccountIsWarnedThenDeletedAfterTheWarningPeriod(): void
    {
        $id = $this->account(daysUnseen: 120);

        $out = $this->runPrune(['--live' => true]);
        $this->assertStringContainsString('warned', $out);
        $this->assertNotNull($this->find($id), 'A warning run must never delete.');
        $this->assertSame(1, $this->warningCount($id));
        $this->assertEmailCount(1);

        // Not yet due: the second run inside the warning period changes nothing.
        $this->runPrune(['--live' => true]);
        $this->assertNotNull($this->find($id));

        $this->expireWarning($id);
        $email = $this->find($id)->getEmail();
        $this->runPrune(['--live' => true]);

        $this->em->clear();
        $this->assertNull($this->find($id));
        $audit = $this->em->getRepository(DeletionRequest::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($audit);
        $this->assertSame(DeletionRequestStatus::PRUNED_INACTIVE, $audit->getStatus());
    }

    public function testAnAccountIsNeverDeletedWithoutAWarning(): void
    {
        $id = $this->account(daysUnseen: 400);
        $this->runPrune(['--live' => true]);

        $this->assertNotNull($this->find($id), 'First contact is always a warning, however old the account.');
    }

    public function testSigningInAfterTheWarningKeepsTheAccountAndReArmsTheWarning(): void
    {
        $id = $this->account(daysUnseen: 120);
        $this->runPrune(['--live' => true]);
        $this->expireWarning($id);

        $this->db->executeStatement('UPDATE "user" SET last_login_at = NOW() WHERE id = :id', ['id' => $id]);
        $this->runPrune(['--live' => true]);

        $this->assertNotNull($this->find($id));
        $this->assertSame(0, $this->warningCount($id), 'The stale warning must go so a later lapse starts over.');
    }

    public function testAccountsWithAnyPlayHistoryOrCreditAreNeverCandidates(): void
    {
        $withClub = $this->account(daysUnseen: 200);
        $this->em->persist(new Club('Prune Test FC ' . bin2hex(random_bytes(3)), $this->find($withClub)));
        $this->em->flush();

        $withCredit = $this->account(daysUnseen: 200);
        self::getContainer()->get(UserLedgerService::class)->append(
            $this->find($withCredit), null, UserLedgerEntryType::STARTING_GRANT, 100, 'test', 'prune-' . bin2hex(random_bytes(5)),
        );

        $withArchive = $this->account(daysUnseen: 200);
        $this->db->executeStatement('UPDATE "user" SET debug_logging_enabled = true WHERE id = :id', ['id' => $withArchive]);

        $admin = $this->account(daysUnseen: 200, roles: ['ROLE_ADMIN']);
        $recent = $this->account(daysUnseen: 10);
        $protected = $this->account(daysUnseen: 200);
        $this->protect($this->find($protected)->getEmail());

        foreach ([$withClub, $withCredit, $withArchive, $admin, $recent, $protected] as $id) {
            $this->expireWarningFor($id);
        }

        $this->runPrune(['--live' => true]);
        $this->em->clear();

        foreach ([$withClub, $withCredit, $withArchive, $admin, $recent, $protected] as $id) {
            $this->assertNotNull($this->find($id));
            $this->assertSame(0, $this->warningCount($id), 'A non-candidate must not even be warned.');
        }
    }

    public function testARecentDeviceCheckInCountsAsSeen(): void
    {
        $id = $this->account(daysUnseen: 200);
        $this->db->executeStatement(
            "INSERT INTO user_device (id, user_id, device_token, platform, last_active_at, created_at) VALUES (gen_random_uuid(), :id, :tok, 'ios', NOW(), NOW())",
            ['id' => $id, 'tok' => 'prune-' . bin2hex(random_bytes(8))],
        );

        $this->runPrune(['--live' => true]);
        $this->assertSame(0, $this->warningCount($id));
    }

    public function testWithoutLiveNothingIsSentOrDeleted(): void
    {
        $id = $this->account(daysUnseen: 120);
        $out = $this->runPrune();

        $this->assertStringContainsString('would warn', $out);
        $this->assertSame(0, $this->warningCount($id));
        $this->assertEmailCount(0);
    }

    public function testModeOffMakesALiveRunANoOp(): void
    {
        $this->setMode('off');
        $id = $this->account(daysUnseen: 120);
        $out = $this->runPrune(['--live' => true]);

        $this->assertStringContainsString('OFF', $out);
        $this->assertSame(0, $this->warningCount($id));
    }

    public function testModeDryRunMakesALiveRunReportOnly(): void
    {
        $this->setMode('dry_run');
        $id = $this->account(daysUnseen: 120);
        $this->runPrune(['--live' => true]);

        $this->assertSame(0, $this->warningCount($id));
        $this->assertEmailCount(0);
    }

    public function testALiveRunAbortsWhenThePoolIsLargerThanExpected(): void
    {
        $this->account(daysUnseen: 120);
        $this->account(daysUnseen: 120);

        $command = (new Application(self::$kernel))->find('app:users:prune-inactive');
        $tester  = new CommandTester($command);
        $code    = $tester->execute(['--live' => true, '--abort-above' => '0']);

        $this->assertSame(1, $code);
        $this->assertEmailCount(0);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param list<string> $roles */
    private function account(int $daysUnseen, array $roles = []): string
    {
        $user = new User('prune-' . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $user->setRoles($roles === [] ? [User::ROLE_CLUB] : $roles);
        $this->em->persist($user);
        $this->em->flush();

        $this->db->executeStatement(
            'UPDATE "user" SET created_at = NOW() - make_interval(days => :d), last_login_at = NULL WHERE id = :id',
            ['d' => $daysUnseen, 'id' => (string) $user->getId()],
        );

        return (string) $user->getId();
    }

    private function find(string $id): ?User
    {
        return $this->em->getRepository(User::class)->find($id);
    }

    private function warningCount(string $id): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM inactive_account_warning WHERE user_id = :id', ['id' => $id]);
    }

    /** Backdates an existing warning so it is due. */
    private function expireWarning(string $id): void
    {
        $this->db->executeStatement(
            "UPDATE inactive_account_warning SET warned_at = NOW() - INTERVAL '20 days', delete_after = NOW() - INTERVAL '1 day' WHERE user_id = :id",
            ['id' => $id],
        );
    }

    /** Plants a due warning directly, to prove protections hold even then. */
    private function expireWarningFor(string $id): void
    {
        $this->db->executeStatement(
            "INSERT INTO inactive_account_warning (id, user_id, warned_at, delete_after) VALUES (gen_random_uuid(), :id, NOW() - INTERVAL '20 days', NOW() - INTERVAL '1 day')",
            ['id' => $id],
        );
    }

    private function setMode(string $mode): void
    {
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        $config->setInactiveAccountPruneMode($mode);
        $config->setInactiveAccountPruneProtectedEmails('');
        $this->em->flush();
    }

    private function protect(string $email): void
    {
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        $config->setInactiveAccountPruneProtectedEmails($email);
        $this->em->flush();
    }

    /** @param array<string, mixed> $input */
    private function runPrune(array $input = []): string
    {
        $command = (new Application(self::$kernel))->find('app:users:prune-inactive');
        $tester  = new CommandTester($command);
        $tester->execute($input);

        return $tester->getDisplay();
    }

    private function purge(): void
    {
        $ids = "SELECT id FROM \"user\" WHERE email LIKE 'prune-%@test.local'";
        $this->db->executeStatement("DELETE FROM club WHERE user_id IN ($ids)");
        $this->db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'prune-%@test.local'");
        $this->db->executeStatement("DELETE FROM deletion_request WHERE email LIKE 'prune-%@test.local'");
    }
}
