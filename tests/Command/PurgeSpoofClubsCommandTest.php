<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Club;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:clubs:purge-spoof deletes whole accounts, so the assertions that matter are the
 * negative ones: a real club must survive, and an account that owns even one real club must
 * be skipped entirely rather than partially cleaned.
 */
class PurgeSpoofClubsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function spoofUser(): User
    {
        $user = new User('spoof-' . bin2hex(random_bytes(6)) . User::SPOOF_EMAIL_DOMAIN);
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);

        return $user;
    }

    private function realUser(): User
    {
        $user = new User('real-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);

        return $user;
    }

    private function club(User $user, string $name, bool $spoof): Club
    {
        $club = new Club($name, $user);
        $club->setSpoof($spoof);
        $this->em->persist($club);

        return $club;
    }

    /** Named distinctly from the inherited static runCommand() — see CLAUDE.md. */
    private function runPurge(array $input = []): CommandTester
    {
        $command = (new Application(self::$kernel))->find('app:clubs:purge-spoof');
        $tester  = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    public function testDeletesASpoofClubAndItsThrowawayAccount(): void
    {
        $user = $this->spoofUser();
        $club = $this->club($user, 'Spoof United ' . uniqid('', true), spoof: true);
        $this->em->flush();

        $clubId = (string) $club->getId();
        $userId = (string) $user->getId();

        $this->runPurge();
        $this->em->clear();

        $this->assertNull($this->em->getRepository(Club::class)->find($clubId));
        $this->assertNull($this->em->getRepository(User::class)->find($userId), 'The throwaway account must go with its club, not linger as an orphan.');
    }

    public function testLeavesRealClubsAlone(): void
    {
        $realUser = $this->realUser();
        $realClub = $this->club($realUser, 'Real Rovers ' . uniqid('', true), spoof: false);

        $spoofUser = $this->spoofUser();
        $this->club($spoofUser, 'Spoof City ' . uniqid('', true), spoof: true);
        $this->em->flush();

        $realClubId = (string) $realClub->getId();
        $realUserId = (string) $realUser->getId();

        $this->runPurge();
        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(Club::class)->find($realClubId));
        $this->assertNotNull($this->em->getRepository(User::class)->find($realUserId));
    }

    /**
     * deleteAccount() removes ALL of a user's clubs, so an account holding one spoof and one
     * real club must be skipped whole. createSpoofClub() always mints a fresh user so this
     * should be unreachable — which is exactly why it is asserted rather than assumed.
     */
    public function testSkipsAnAccountThatAlsoOwnsARealClub(): void
    {
        $user      = $this->spoofUser();
        $spoofClub = $this->club($user, 'Mixed Spoof ' . uniqid('', true), spoof: true);
        $realClub  = $this->club($user, 'Mixed Real ' . uniqid('', true), spoof: false);
        $this->em->flush();

        $spoofClubId = (string) $spoofClub->getId();
        $realClubId  = (string) $realClub->getId();

        $tester = $this->runPurge();
        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(Club::class)->find($realClubId), 'A real club must never be collateral damage.');
        $this->assertNotNull($this->em->getRepository(Club::class)->find($spoofClubId), 'The whole account is skipped, so its spoof club stays too — reported, not silently half-deleted.');
        $this->assertStringContainsString('Skipped', $tester->getDisplay());
    }

    public function testSkipsASpoofFlaggedClubOwnedByARealAccount(): void
    {
        $user = $this->realUser();
        $club = $this->club($user, 'Spoof Flagged ' . uniqid('', true), spoof: true);
        $this->em->flush();

        $clubId = (string) $club->getId();
        $userId = (string) $user->getId();

        $tester = $this->runPurge();
        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(Club::class)->find($clubId));
        $this->assertNotNull($this->em->getRepository(User::class)->find($userId), 'A real email is not a throwaway spoof account, whatever the club flag says.');
        $this->assertStringContainsString('not a spoof account', $tester->getDisplay());
    }

    public function testDryRunReportsWithoutDeleting(): void
    {
        $user = $this->spoofUser();
        $club = $this->club($user, 'Dry Run FC ' . uniqid('', true), spoof: true);
        $this->em->flush();

        $clubId = (string) $club->getId();

        $tester = $this->runPurge(['--dry-run' => true]);
        $this->em->clear();

        $this->assertStringContainsString('Would delete', $tester->getDisplay());
        $this->assertNotNull($this->em->getRepository(Club::class)->find($clubId));
    }
}
